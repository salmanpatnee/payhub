<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Square\Environments;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\SquareClient;
use Square\Types\Money;
use Stripe\StripeClient;

class ClientPaymentController extends Controller
{
    public function show(Payment $payment): Response|RedirectResponse
    {
        $payment->loadMissing(['brand', 'stripeAccount', 'squareAccount']);

        // D-03 + D-12: Guard check BEFORE any processor call.
        // failed is allowed through so a declined card can be retried.
        if (! in_array($payment->status, ['pending', 'failed'])) {
            return Inertia::render('ClientPayment/Unavailable', [
                'status' => $payment->status,
                'brand' => $this->brandProps($payment->brand),
            ]);
        }

        // Square embedded: no pre-charge. The Web Payments SDK tokenizes client-side and POSTs
        // the token to chargeSquare(). NEVER expose access_token to the client.
        if ($payment->provider === 'square') {
            return Inertia::render('ClientPayment/Pay', [
                'provider' => 'square',
                'payment' => $this->paymentProps($payment),
                'brand' => $this->brandProps($payment->brand),
                'squareAccount' => [
                    'application_id' => $payment->squareAccount->application_id,
                    'location_id' => $payment->squareAccount->location_id,
                    'environment' => $payment->squareAccount->environment,
                ],
            ]);
        }

        // D-01: Per-account StripeClient — NEVER Stripe::setApiKey() globally
        // secret_key auto-decrypted by Laravel encrypted cast
        // app()->make() allows test mocking via app()->bind(StripeClient::class, ...)
        $stripe = app()->make(StripeClient::class, ['config' => $payment->stripeAccount->secret_key]);

        // CR-01 fix: retrieve-and-reuse pattern — no duplicate PIs on refresh
        // Confirmable states: requires_payment_method, requires_confirmation, requires_action
        // Terminal states: succeeded, canceled — must create a new PI
        $confirmableStates = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

        if ($payment->stripe_payment_intent_id) {
            $pi = $stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id);

            if (! in_array($pi->status, $confirmableStates)) {
                // Existing PI is in a terminal state — create a fresh one
                $pi = $stripe->paymentIntents->create([
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'automatic_payment_methods' => ['enabled' => true],
                    'metadata' => [
                        'reference_code' => $this->formatReferenceCode($payment->reference_code),
                        'payment_uuid' => $payment->uuid,
                    ],
                ], ['idempotency_key' => 'pi-recreate-'.$payment->uuid.'-'.substr(md5($payment->stripe_payment_intent_id ?? ''), 0, 8)]);
                $payment->update(['stripe_payment_intent_id' => $pi->id]);
            }
            // else: reuse the existing confirmable PI — do NOT update stripe_payment_intent_id
        } else {
            // No PI exists yet — create one
            $pi = $stripe->paymentIntents->create([
                'amount' => $payment->amount,   // integer cents from DB — SEC-02
                'currency' => $payment->currency, // 'usd' or 'gbp'
                'automatic_payment_methods' => ['enabled' => true], // handles 3DS automatically — CLIENT-05
                'metadata' => [
                    'reference_code' => $this->formatReferenceCode($payment->reference_code),
                    'payment_uuid' => $payment->uuid,
                ],
            ], ['idempotency_key' => 'pi-create-'.$payment->uuid]);
            // D-02: Store PI ID so Phase 6 webhook handler can look up the Payment
            $payment->update(['stripe_payment_intent_id' => $pi->id]);
        }

        // SEC-04: clientSecret only in Inertia props — NEVER logged, NEVER in URL
        return Inertia::render('ClientPayment/Pay', [
            'provider' => 'stripe',
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'stripeAccount' => ['publishable_key' => $payment->stripeAccount->publishable_key],
            'clientSecret' => $pi->client_secret,
        ]);
    }

    /**
     * Square embedded charge endpoint. Receives a tokenized card (source_id) and optional
     * SCA verification_token, then charges via the per-account Square client.
     *
     * The response only correlates the Square payment id — the authoritative status write
     * still comes from the payment.updated webhook (CLAUDE.md rule: never trust client charge).
     */
    public function chargeSquare(Request $request, Payment $payment): JsonResponse
    {
        $payment->loadMissing('squareAccount');

        // Guard mirrors show(): only Square payments in a chargeable state.
        if ($payment->provider !== 'square' || ! in_array($payment->status, ['pending', 'failed'])) {
            return response()->json(['ok' => false, 'error' => 'This payment cannot be processed.'], 422);
        }

        $validated = $request->validate([
            'source_id' => ['required', 'string'],
            'verification_token' => ['nullable', 'string'],
        ]);

        // Per-account SquareClient — NEVER a global token. app()->make() allows test mocking
        // via app()->bind(SquareClient::class, ...).
        $square = app()->make(SquareClient::class, [
            'token' => $payment->squareAccount->access_token,
            'options' => ['baseUrl' => $payment->squareAccount->environment === 'production'
                ? Environments::Production->value
                : Environments::Sandbox->value],
        ]);

        try {
            $response = $square->payments->create(new CreatePaymentRequest([
                'idempotencyKey' => (string) Str::uuid(),
                'sourceId' => $validated['source_id'],
                'verificationToken' => $validated['verification_token'] ?? null,
                'amountMoney' => new Money([
                    'amount' => $payment->amount,                 // integer cents from DB — SEC-02, never from client
                    'currency' => strtoupper($payment->currency), // 'USD' | 'GBP'
                ]),
                'locationId' => $payment->squareAccount->location_id,
                'referenceId' => (string) $payment->reference_code,
                'note' => $payment->uuid,
            ]));
        } catch (SquareApiException) {
            // Sanitized — never surface card data, tokens, or raw processor errors.
            return response()->json(['ok' => false, 'error' => 'Your payment could not be processed. Please check your card details and try again.'], 422);
        } catch (SquareException) {
            return response()->json(['ok' => false, 'error' => 'Could not reach the payment processor. Please try again.'], 502);
        }

        $squarePaymentId = $response->getPayment()?->getId();

        if ($squarePaymentId) {
            // Store for webhook correlation only. Do NOT set status to completed here.
            $payment->update(['square_payment_id' => $squarePaymentId]);
        }

        return response()->json(['ok' => true]);
    }

    public function success(Payment $payment): Response|RedirectResponse
    {
        // D-04: Stripe redirects with ?redirect_status=succeeded on success
        // SEC-04: Only read redirect_status — discard payment_intent_client_secret query param entirely
        if (request('redirect_status') !== 'succeeded') {
            return redirect()->route('pay.failed', $payment->uuid);
        }

        $payment->loadMissing('brand');

        // CR-02 fix: block cancelled payments from showing success via crafted URLs.
        // failed is intentionally excluded: after a retry Stripe redirects before the webhook fires,
        // so status is still 'failed' at this instant — the webhook sets it to 'completed' shortly after.
        if ($payment->status === 'cancelled') {
            return Inertia::render('ClientPayment/Unavailable', [
                'status' => $payment->status,
                'brand' => $this->brandProps($payment->brand),
            ]);
        }

        return Inertia::render('ClientPayment/Success', [
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
        ]);
    }

    public function failed(Payment $payment): Response
    {
        $payment->loadMissing('brand');

        return Inertia::render('ClientPayment/Failed', [
            'payment' => [
                'uuid' => $payment->uuid,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ],
            'brand' => $this->brandProps($payment->brand),
        ]);
    }

    private function formatReferenceCode(?int $code): string
    {
        return '#'.str_pad((string) ($code ?? 0), 6, '0', STR_PAD_LEFT);
    }

    private function brandProps(Brand $brand): array
    {
        return [
            'name' => $brand->name,
            'slug' => $brand->slug,
            'logo_url' => $brand->logo_path ? '/storage/'.$brand->logo_path : null,
            'primary_color' => $brand->primary_color,
            'secondary_color' => $brand->secondary_color,
        ];
    }

    private function paymentProps(Payment $payment): array
    {
        return [
            'uuid' => $payment->uuid,
            'reference_code' => $payment->reference_code,
            'amount' => $payment->amount,   // integer cents
            'currency' => $payment->currency, // 'usd' or 'gbp'
            'service' => $payment->service,  // nullable
            'package' => $payment->package,  // nullable — D-07: show package on pay page
            // client_name, client_email, note intentionally excluded — D-07
        ];
    }
}
