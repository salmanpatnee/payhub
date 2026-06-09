<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentConsentRequest;
use App\Models\Brand;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\StripeClient;

class ClientPaymentController extends Controller
{
    public function show(Payment $payment): Response|RedirectResponse
    {
        $payment->loadMissing(['brand', 'stripeAccount']);

        // D-03 + D-12: Guard check BEFORE any StripeClient call.
        // failed is allowed through so a declined card can be retried — the existing PI reuse
        // logic below handles confirmable (requires_payment_method) vs terminal PI states.
        if (! in_array($payment->status, ['pending', 'failed'])) {
            return Inertia::render('ClientPayment/Unavailable', [
                'status' => $payment->status,
                'brand' => $this->brandProps($payment->brand),
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
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'stripeAccount' => ['publishable_key' => $payment->stripeAccount->publishable_key],
            'clientSecret' => $pi->client_secret,
            'policies' => $this->policyProps(),
        ]);
    }

    /**
     * Record the customer's acceptance of the policies for the audit trail.
     * Called client-side immediately before Stripe confirmPayment() — there is
     * no Laravel submit round-trip for the payment itself.
     */
    public function storeConsent(StorePaymentConsentRequest $request, Payment $payment): JsonResponse
    {
        // Same guard as show(): only payable (pending/failed) payments can record consent.
        abort_unless(in_array($payment->status, ['pending', 'failed']), 422);

        $payment->consents()->create([
            'policy_versions' => collect(config('policies'))
                ->map(fn (array $policy): string => $policy['version'])
                ->all(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accepted_at' => now(),
        ]);

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

    /**
     * @return array<int, array{key: string, title: string, url: string, version: string}>
     */
    private function policyProps(): array
    {
        return collect(config('policies'))
            ->map(fn (array $policy, string $key): array => [
                'key' => $key,
                'title' => $policy['title'],
                'url' => $policy['url'],
                'version' => $policy['version'],
            ])
            ->values()
            ->all();
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
