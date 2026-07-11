<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Http\Requests\StorePaymentConsentRequest;
use App\Models\Brand;
use App\Models\Payment;
use App\Services\Revolut\RevolutClient;
use App\Services\Viva\VivaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Sentry\Breadcrumb;
use Square\Environments;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\SquareClient;
use Square\Types\Money;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class ClientPaymentController extends Controller
{
    public function show(Payment $payment): Response|RedirectResponse
    {
        $payment->loadMissing('brand');

        // D-03 + D-12: Guard check BEFORE any provider call.
        // failed is allowed through so a declined card can be retried — the per-provider
        // reuse logic below handles confirmable vs terminal states.
        if (! in_array($payment->status, ['pending', 'failed'])) {
            return Inertia::render('ClientPayment/Unavailable', [
                'status' => $payment->status,
                'brand' => $this->brandProps($payment->brand),
                'provider' => $payment->provider->value,
            ]);
        }

        return match ($payment->provider) {
            PaymentProvider::Revolut => $this->showRevolut($payment),
            PaymentProvider::Square => $this->showSquare($payment),
            PaymentProvider::Viva => $this->showViva($payment),
            default => $this->showStripe($payment),
        };
    }

    private function showStripe(Payment $payment): Response
    {
        $payment->loadMissing('stripeAccount');

        // D-01: Per-account StripeClient — NEVER Stripe::setApiKey() globally
        // secret_key auto-decrypted by Laravel encrypted cast
        // app()->make() allows test mocking via app()->bind(StripeClient::class, ...)
        $stripe = app()->make(StripeClient::class, ['config' => $payment->stripeAccount->secret_key]);

        // CR-01 fix: retrieve-and-reuse pattern — no duplicate PIs on refresh
        $pi = $this->reusableStripePaymentIntent($stripe, $payment);

        if ($pi === null) {
            $pi = $stripe->paymentIntents->create([
                'amount' => $payment->amount,   // integer cents from DB — SEC-02
                'currency' => $payment->currency, // 'usd' or 'gbp'
                'automatic_payment_methods' => ['enabled' => true], // handles 3DS automatically — CLIENT-05
                'description' => $this->buildDescription($payment),
                'metadata' => [
                    'reference_code' => $payment->formattedReferenceCode(),
                    'payment_uuid' => $payment->uuid,
                ],
            ], ['idempotency_key' => $this->stripeIdempotencyKey($payment)]);
            // D-02: Store PI ID so the webhook handler can look up the Payment
            $payment->update(['stripe_payment_intent_id' => $pi->id]);
        }

        // SEC-04: clientSecret only in Inertia props — NEVER logged, NEVER in URL
        return Inertia::render('ClientPayment/Pay', [
            'provider' => 'stripe',
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'stripeAccount' => ['publishable_key' => $payment->stripeAccount->publishable_key],
            'clientSecret' => $pi->client_secret,
            'policies' => $this->policyProps(),
        ]);
    }

    /**
     * Resolve the stored PaymentIntent if it is still usable, or null if a fresh one
     * must be created. A PI is unusable when it no longer exists on the account (the
     * payment was moved between Stripe accounts, leaving a dangling id), when it has
     * reached a terminal state, or when the payment's amount/currency has since been
     * edited — reusing a drifted PI would charge the client the pre-edit amount.
     */
    private function reusableStripePaymentIntent(StripeClient $stripe, Payment $payment): ?PaymentIntent
    {
        if (! $payment->stripe_payment_intent_id) {
            return null;
        }

        try {
            $pi = $stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id);
        } catch (InvalidRequestException) {
            // PaymentIntents are scoped to the account that created them, so a 404 here
            // means the stored id belongs to a different account. Recover by creating a
            // fresh PI rather than 500ing on the client's pay page.
            Log::warning('Stale Stripe PaymentIntent — creating a fresh one', [
                'payment_uuid' => $payment->uuid,
                'stripe_account_id' => $payment->stripe_account_id,
                'stale_payment_intent_id' => $payment->stripe_payment_intent_id,
            ]);

            \Sentry\addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_WARNING,
                Breadcrumb::TYPE_DEFAULT,
                'stripe',
                'Stale PaymentIntent, recreating',
                [
                    'payment_uuid' => $payment->uuid,
                    'stripe_account_id' => $payment->stripe_account_id,
                    'stale_payment_intent_id' => $payment->stripe_payment_intent_id,
                ],
            ));

            return null;
        }

        // Confirmable states: requires_payment_method, requires_confirmation, requires_action
        // Terminal states: succeeded, canceled — must create a new PI
        $confirmableStates = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

        if (! in_array($pi->status, $confirmableStates)) {
            return null;
        }

        if ((int) $pi->amount !== (int) $payment->amount || $pi->currency !== $payment->currency) {
            return null;
        }

        return $pi;
    }

    /**
     * Stable across page refreshes so a reload never mints a duplicate PI, but distinct
     * once the payment moves account or its amount/currency is edited — precisely the
     * cases where a new PI is required. Keying on the uuid alone would make Stripe
     * replay the original (possibly dangling) PI for 24h.
     */
    private function stripeIdempotencyKey(Payment $payment): string
    {
        return sprintf(
            'pi-%s-%d-%s-%d',
            $payment->uuid,
            $payment->stripe_account_id,
            $payment->currency,
            $payment->amount,
        );
    }

    private function showRevolut(Payment $payment): Response
    {
        $payment->loadMissing('revolutAccount');

        // Per-account RevolutClient — mirrors the per-account StripeClient rule.
        $revolut = app()->make(RevolutClient::class, ['secretKey' => $payment->revolutAccount->secret_key]);

        // Retrieve-and-reuse: reuse an order still awaiting payment; recreate once it
        // has reached any terminal state (completed/cancelled/failed/authorised).
        $payableStates = ['pending', 'processing'];

        if ($payment->revolut_order_id) {
            $order = $revolut->retrieveOrder($payment->revolut_order_id);

            if (! in_array($order['state'] ?? '', $payableStates, true)) {
                $order = $this->createRevolutOrder($revolut, $payment);
                $payment->update(['revolut_order_id' => $order['id']]);
            }
        } else {
            $order = $this->createRevolutOrder($revolut, $payment);
            $payment->update(['revolut_order_id' => $order['id']]);
        }

        $mode = config('services.revolut.environment', 'sandbox') === 'prod' ? 'prod' : 'sandbox';

        // SEC-04 analog: orderToken only in Inertia props — NEVER logged, NEVER in URL
        return Inertia::render('ClientPayment/PayRevolut', [
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'revolutAccount' => ['public_key' => $payment->revolutAccount->public_key],
            'orderToken' => $order['token'],
            'mode' => $mode,
            // Revolut's Card Field requires a customer email + cardholder name on
            // submit. Prefill those captured at payment creation when present; the
            // customer can edit them on the page.
            'customerEmail' => $payment->client_email,
            'customerName' => $payment->client_name,
            'policies' => $this->policyProps(),
        ]);
    }

    /**
     * Square embedded: no pre-charge. The Web Payments SDK tokenizes client-side and POSTs
     * the token to chargeSquare(). NEVER expose access_token to the client.
     */
    private function showSquare(Payment $payment): Response
    {
        $payment->loadMissing('squareAccount');

        return Inertia::render('ClientPayment/Pay', [
            'provider' => 'square',
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'squareAccount' => [
                'application_id' => $payment->squareAccount->application_id,
                'location_id' => $payment->squareAccount->location_id,
                'environment' => $payment->squareAccount->environment,
            ],
            'policies' => $this->policyProps(),
        ]);
    }

    /**
     * Viva has no embedded card field — Smart Checkout is a hosted redirect
     * page, confirmed against Viva's own docs (no Stripe-Elements-equivalent
     * embedding option exists). Renders our own branded summary page first
     * (matching Stripe/Revolut/Square's UX shape) with an explicit "Proceed
     * to payment" action — the actual redirect to Viva happens client-side
     * from PayViva.vue after consent is recorded.
     *
     * Reuses an existing order code rather than recreating one on every visit:
     * unlike Stripe/Revolut, Viva has no confirmed cheap "retrieve order"
     * status check (see .planning/research/VIVA_PAYMENTS.md), and recreating
     * on each show() would silently break webhook correlation for a customer
     * who has an older order still open in another tab (the webhook looks up
     * the Payment by viva_order_code). Revisit this once Viva's order-expiry
     * behavior is confirmed against a live sandbox.
     */
    private function showViva(Payment $payment): Response
    {
        $payment->loadMissing('vivaAccount');

        $viva = app()->make(VivaClient::class, [
            'clientId' => $payment->vivaAccount->client_id,
            'clientSecret' => $payment->vivaAccount->client_secret,
            'merchantId' => $payment->vivaAccount->merchant_id,
            'apiKey' => $payment->vivaAccount->api_key,
            'sourceCode' => $payment->vivaAccount->source_code,
            'environment' => $payment->vivaAccount->environment,
        ]);

        $orderCode = $payment->viva_order_code;

        if (! $orderCode) {
            $order = $viva->createOrder([
                'amount' => $payment->amount,   // integer cents from DB — SEC-02, never from client
                'currencyCode' => 826,          // GBP — StorePaymentRequest already guarantees currency=gbp for Viva
                'customerTrns' => $this->buildDescription($payment),
                'merchantTrns' => $payment->formattedReferenceCode(),
                'sourceCode' => $payment->vivaAccount->source_code,
            ]);

            // Viva's own documentation and official code samples disagree on the
            // response casing (orderCode vs OrderCode) across API generations —
            // accept either rather than guessing, and fail loudly with the raw
            // response if neither is present so this never resurfaces as an
            // opaque "undefined array key" crash.
            $rawOrderCode = $order['orderCode'] ?? $order['OrderCode'] ?? null;

            if ($rawOrderCode === null) {
                throw new \RuntimeException('Viva order creation did not return an order code: '.json_encode($order));
            }

            $orderCode = (string) $rawOrderCode;

            $payment->update([
                'viva_order_code' => $orderCode,
                'viva_account_id' => $payment->vivaAccount->id,
            ]);
        }

        return Inertia::render('ClientPayment/PayViva', [
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'checkoutUrl' => $viva->checkoutUrl($orderCode, $payment->brand->primary_color),
            'policies' => $this->policyProps(),
        ]);
    }

    /**
     * Create a Revolut Merchant API order from the server-side Payment record.
     * Amount is read from the DB (integer minor units) — never from the client.
     *
     * @return array<string, mixed>
     */
    private function createRevolutOrder(RevolutClient $revolut, Payment $payment): array
    {
        return $revolut->createOrder([
            'amount' => $payment->amount,                  // integer minor units — SEC-02
            'currency' => strtoupper($payment->currency),  // Revolut expects ISO 4217 (GBP/USD)
            'capture_mode' => 'automatic',                 // auto-capture → ORDER_COMPLETED is the success signal
            'description' => $this->buildDescription($payment),
            // Correlate the Revolut order with our system. For Merchant API 2024-09-01
            // the external reference + metadata live under merchant_order_data — the
            // legacy top-level merchant_order_ext_ref is ignored (blank in exports).
            // `reference` surfaces in the transactions CSV's merchant_order_ext_ref
            // column; metadata mirrors the Stripe PaymentIntent (string values, keys
            // starting with a letter).
            'merchant_order_data' => [
                'reference' => $payment->formattedReferenceCode(),
                'metadata' => [
                    'reference_code' => $payment->formattedReferenceCode(),
                    'payment_uuid' => $payment->uuid,
                ],
            ],
        ]);
    }

    /**
     * Record the customer's acceptance of the policies for the audit trail.
     * Called client-side immediately before the provider charge/confirm — there is
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
        if ($payment->provider !== PaymentProvider::Square || ! in_array($payment->status, ['pending', 'failed'])) {
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
                'referenceId' => (string) $payment->formattedReferenceCode(),
                'note' => $this->buildDescription($payment),
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
        // D-04: Stripe redirects with ?redirect_status=succeeded on success.
        // SEC-04: Only read redirect_status — discard payment_intent_client_secret query param entirely.
        // Revolut's Card Field and Square's embedded charge complete via a callback (no redirect
        // param), so the redirect_status gate applies to Stripe only; truth still comes from the
        // webhook either way.
        if ($payment->provider === PaymentProvider::Stripe && request('redirect_status') !== 'succeeded') {
            return redirect()->route('pay.failed', $payment->uuid);
        }

        // Viva's exact return query param names are unconfirmed against a live
        // sandbox (see .planning/research/VIVA_PAYMENTS.md) — intentionally no
        // fast-fail query-param check here yet, to avoid guessing a wrong param
        // name and incorrectly redirecting every real Viva success to /failed.
        // The status === 'cancelled' guard below still applies regardless, and
        // the authoritative status write remains webhook-only either way.

        $payment->loadMissing(['brand', 'stripeAccount', 'revolutAccount', 'squareAccount', 'vivaAccount']);

        // CR-02 fix: block cancelled payments from showing success via crafted URLs.
        // failed is intentionally excluded: after a retry Stripe redirects before the webhook fires,
        // so status is still 'failed' at this instant — the webhook sets it to 'completed' shortly after.
        if ($payment->status === 'cancelled') {
            return Inertia::render('ClientPayment/Unavailable', [
                'status' => $payment->status,
                'brand' => $this->brandProps($payment->brand),
                'provider' => $payment->provider->value,
            ]);
        }

        return Inertia::render('ClientPayment/Success', [
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'provider' => $payment->provider->value,
        ]);
    }

    /**
     * Generic Viva Smart Checkout return endpoints — see the routes/web.php comment
     * for why these can't be per-payment URLs. `s` is Viva's order code query param,
     * which we already store as `viva_order_code` for webhook correlation.
     */
    public function vivaReturnSuccess(Request $request): RedirectResponse
    {
        $payment = Payment::where('viva_order_code', $request->query('s'))->firstOrFail();

        return redirect()->route('pay.success', $payment);
    }

    public function vivaReturnFailed(Request $request): RedirectResponse
    {
        $payment = Payment::where('viva_order_code', $request->query('s'))->firstOrFail();

        return redirect()->route('pay.failed', $payment);
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
            'provider' => $payment->provider->value,
        ]);
    }

    private function buildDescription(Payment $payment): string
    {
        return 'Reference code for this order is: '.$payment->formattedReferenceCode();
    }

    /**
     * Policies for the consent UI. The PDF is no longer surfaced — instead the
     * versioned Markdown in resources/policies is rendered to HTML and shown in
     * an in-app modal. Render is cached per version (content only changes on a
     * version bump).
     *
     * @return array<int, array{key: string, title: string, version: string, html: string}>
     */
    private function policyProps(): array
    {
        return collect(config('policies'))
            ->map(fn (array $policy, string $key): array => [
                'key' => $key,
                'title' => $policy['title'],
                'version' => $policy['version'],
                'html' => $this->policyHtml($key, $policy['version']),
            ])
            ->values()
            ->all();
    }

    private function policyHtml(string $key, string $version): string
    {
        return cache()->rememberForever(
            "policy.html.{$key}.{$version}",
            fn (): string => Str::markdown(
                (string) file_get_contents(resource_path("policies/{$key}.md"))
            )
        );
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
            'reference_code' => $payment->formattedReferenceCode(),
            'amount' => $payment->amount,   // integer cents
            'currency' => $payment->currency, // 'usd' or 'gbp'
            'service' => $payment->service,  // nullable
            'package' => $payment->package,  // nullable — D-07: show package on pay page
            // client_name, client_email, note intentionally excluded — D-07
        ];
    }
}
