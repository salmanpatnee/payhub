<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\StripeClient;

class ClientPaymentController extends Controller
{
    public function show(Payment $payment): Response|RedirectResponse
    {
        $payment->loadMissing(['brand', 'stripeAccount']);

        // D-03 + D-12: Guard check BEFORE any StripeClient call
        if ($payment->status !== 'pending') {
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
                ]);
                $payment->update(['stripe_payment_intent_id' => $pi->id]);
            }
            // else: reuse the existing confirmable PI — do NOT update stripe_payment_intent_id
        } else {
            // No PI exists yet — create one
            $pi = $stripe->paymentIntents->create([
                'amount' => $payment->amount,   // integer cents from DB — SEC-02
                'currency' => $payment->currency, // 'usd' or 'gbp'
                'automatic_payment_methods' => ['enabled' => true], // handles 3DS automatically — CLIENT-05
            ]);
            // D-02: Store PI ID so Phase 6 webhook handler can look up the Payment
            $payment->update(['stripe_payment_intent_id' => $pi->id]);
        }

        // SEC-04: clientSecret only in Inertia props — NEVER logged, NEVER in URL
        return Inertia::render('ClientPayment/Pay', [
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'stripeAccount' => ['publishable_key' => $payment->stripeAccount->publishable_key],
            'clientSecret' => $pi->client_secret,
        ]);
    }

    public function success(Payment $payment): Response|RedirectResponse
    {
        // D-04: Stripe redirects with ?redirect_status=succeeded on success
        // SEC-04: Only read redirect_status — discard payment_intent_client_secret query param entirely
        if (request('redirect_status') !== 'succeeded') {
            return redirect()->route('pay.failed', $payment->uuid);
        }

        $payment->loadMissing('brand');

        // CR-02 fix: server-side status guard — prevent success page spoofing via crafted URLs
        // Even if redirect_status=succeeded is present in the URL, a known-failed or cancelled
        // payment must NOT display a success confirmation (no money changed hands).
        if (in_array($payment->status, ['failed', 'cancelled'])) {
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
            'amount' => $payment->amount,   // integer cents
            'currency' => $payment->currency, // 'usd' or 'gbp'
            'service' => $payment->service,  // nullable
            'package' => $payment->package,  // nullable — D-07: show package on pay page
            // client_name, client_email, note intentionally excluded — D-07
        ];
    }
}
