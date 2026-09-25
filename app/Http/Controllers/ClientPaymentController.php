<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Http\Requests\StorePaymentConsentRequest;
use App\Jobs\ResolveCloverChargeAttempt;
use App\Models\Brand;
use App\Models\CloverChargeAttempt;
use App\Models\Payment;
use App\Services\Clover\CloverChargeClassifier;
use App\Services\Clover\CloverChargeResolver;
use App\Services\Clover\CloverClient;
use App\Services\Revolut\RevolutClient;
use App\Services\Viva\VivaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            PaymentProvider::Clover => $this->showClover($payment),
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
     * Clover Hosted Iframe: the card form is embedded directly on this page
     * (CloverPaymentForm.vue), tokenizes client-side, and POSTs the token to
     * chargeClover(). No session/redirect concept — nothing to reuse here,
     * unlike spec 0001's Hosted Checkout this replaces.
     */
    private function showClover(Payment $payment): Response
    {
        $payment->loadMissing('cloverAccount');

        return Inertia::render('ClientPayment/PayClover', [
            'payment' => $this->paymentProps($payment),
            'brand' => $this->brandProps($payment->brand),
            'cloverAccount' => [
                'merchant_id' => $payment->cloverAccount->merchant_id,
                'api_access_key' => $payment->cloverAccount->api_access_key,
                'environment' => $payment->cloverAccount->environment,
            ],
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

    /**
     * Clover Hosted Iframe embedded charge endpoint. Unlike every other PayHub
     * provider, Clover documents no webhook for this integration type — the
     * synchronous response to /v1/charges IS the authoritative outcome (spec
     * 0002's Decision), classified into approved/declined/hard_error/unknown
     * and applied via the same guarded-update shape HandleSquareWebhookJob
     * and HandleStripeWebhookJob already use.
     *
     * A clover_charge_attempts row is written *before* Clover is ever called,
     * and a per-payment lock (spec 0002, Key invariants) guarantees at most one
     * attempt is ever in flight, so a double-click gets 409, never a second
     * real charge.
     */
    public function chargeClover(Request $request, Payment $payment): JsonResponse
    {
        $payment->loadMissing('cloverAccount');

        if ($payment->provider !== PaymentProvider::Clover
            || ! in_array($payment->status, ['pending', 'failed'], true)
            || ! $payment->cloverAccount
            || ! $payment->cloverAccount->is_active) {
            return response()->json(['error' => 'This payment cannot be processed.'], 422);
        }

        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $lock = Cache::lock("clover-charge-attempt:{$payment->id}", 30);

        if (! $lock->get()) {
            return response()->json(['error' => 'A charge attempt is already in progress for this payment. Please wait a moment.'], 409);
        }

        try {
            // Clover's external_reference_id caps at 12 characters (confirmed against
            // Clover's own docs) — a full UUID (36 chars) fails Clover's own request
            // validation with "invalid_request_error" before the card is ever looked
            // at, which PayHub's classifier then (also wrongly) read as a decline. A
            // short alphanumeric key satisfies the same field sent as both Clover's
            // idempotency key and its external_reference_id (spec 0002's design).
            $idempotencyKey = Str::random(12);

            // Written before Clover is ever called — a durable local record even if
            // the call times out, errors, or returns something PayHub can't classify.
            $attempt = CloverChargeAttempt::create([
                'payment_id' => $payment->id,
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
            ]);

            $clover = app()->make(CloverClient::class, [
                'merchantId' => $payment->cloverAccount->merchant_id,
                'privateToken' => $payment->cloverAccount->private_token,
                'environment' => $payment->cloverAccount->environment,
            ]);

            try {
                $result = $clover->createCharge(
                    $idempotencyKey,
                    $idempotencyKey,
                    $validated['token'],
                    $payment->amount,
                    $payment->currency,
                    [
                        'reference_code' => $payment->formattedReferenceCode(),
                        'payment_uuid' => $payment->uuid,
                    ],
                    $payment->formattedReferenceCode(),
                );
                $body = $result['body'];
                $classification = CloverChargeClassifier::classify($result['status'], $body, $payment->amount, $payment->currency);

                // POST /v1/charges never returns "order" (confirmed live,
                // 2026-09-18) — only a follow-up GET does. One extra read, only
                // for an approved charge, so clover_payment_id (the CSV
                // export's/dashboard's "Provider Reference") is the value the
                // engineer confirmed is actually searchable in Clover's own
                // merchant dashboard, not the API-internal charge id.
                if ($classification === 'approved' && ! isset($body['order']) && isset($body['id'])) {
                    $orderId = $this->fetchCloverOrderId($clover, (string) $body['id'], $attempt->id);

                    if ($orderId !== null) {
                        $body['order'] = $orderId;
                    }
                }

                if ($classification === 'approved' && isset($body['order'])) {
                    $this->noteCloverOrder($clover, (string) $body['order'], $payment, $attempt->id);
                }
            } catch (\Throwable $e) {
                // Timeout, connection error, or any other transport failure before a
                // classifiable response ever arrived — resolved later by the job.
                Log::warning('Clover charge transport failure', [
                    'attempt_id' => $attempt->id,
                    'payment_uuid' => $payment->uuid,
                    'error' => $e->getMessage(),
                ]);
                $body = [];
                $classification = 'unknown';
            }

            app(CloverChargeResolver::class)->resolve($attempt, $classification, $body);
        } finally {
            $lock->release();
        }

        if ($classification === 'unknown') {
            ResolveCloverChargeAttempt::dispatch($attempt->id)->delay(now()->addSeconds(15));
        }

        if ($classification === 'hard_error') {
            return response()->json(['error' => 'Could not reach the payment processor. Please try again.'], 502);
        }

        return response()->json([
            'outcome' => $classification, // approved | declined | unknown
            'message' => match ($classification) {
                'approved' => 'Your payment was successful.',
                'declined' => 'Your card was declined. Please try a different card.',
                default => 'We could not confirm your payment yet.',
            },
        ]);
    }

    /**
     * Best-effort only: the payment is already approved by the time this runs,
     * so a failure here must never break the response — it only leaves
     * clover_payment_id as the charge id instead of the order id.
     */
    private function fetchCloverOrderId(CloverClient $clover, string $chargeId, int $attemptId): ?string
    {
        try {
            $order = $clover->getCharge($chargeId)['body']['order'] ?? null;

            return $order !== null ? (string) $order : null;
        } catch (\Throwable $e) {
            Log::warning('Could not fetch clover order id for cross-checking', [
                'attempt_id' => $attemptId,
                'charge_id' => $chargeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Best-effort only, like fetchCloverOrderId(): writes the reference code
     * onto the Clover order so it shows in Clover's merchant dashboard. A
     * failure (e.g. a token without order-write permission) is logged and
     * never affects the already-approved payment.
     */
    private function noteCloverOrder(CloverClient $clover, string $orderId, Payment $payment, int $attemptId): void
    {
        try {
            $clover->updateOrderNote($orderId, $payment->formattedReferenceCode());
        } catch (\Throwable $e) {
            Log::warning('Could not write reference code to clover order note', [
                'attempt_id' => $attemptId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Polled by the client only while the latest Clover charge attempt is
     * unresolved (AC-9) — read-only, Payment.status is the single source of
     * truth here.
     */
    public function cloverStatus(Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === PaymentProvider::Clover, 404);

        return response()->json(['status' => $payment->status]);
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

        $payment->loadMissing(['brand', 'stripeAccount', 'revolutAccount', 'squareAccount', 'vivaAccount', 'cloverAccount']);

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
