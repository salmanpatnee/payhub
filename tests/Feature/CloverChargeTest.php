<?php

use App\Jobs\ResolveCloverChargeAttempt;
use App\Models\CloverChargeAttempt;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
    Http::preventStrayRequests();
});

function chargeClover(string $uuid, array $body = ['token' => 'clv_test_token']): TestResponse
{
    return test()->postJson("/pay/{$uuid}/clover/charge", $body);
}

function fakeCloverCharge(array $body, int $status = 200): void
{
    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges' => Http::response($body, $status),
        // An approved outcome triggers a follow-up GET for the order id — echo
        // the same body back (no "order" key) so existing charge-id assertions
        // keep working; tests specifically covering the order id fake this
        // pattern themselves with an "order" key included.
        'scl-sandbox.dev.clover.com/v1/charges/*' => Http::response($body, $status),
    ]);
}

// AC-5, AC-6, AC-7: a clean approved response completes the payment in the same
// request, records the charge id and paid_at, and never touches Clover again.
it('completes the payment on an approved synchronous response', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    fakeCloverCharge(['id' => 'chg_123', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd']);

    chargeClover($payment->uuid)
        ->assertOk()
        ->assertJson(['outcome' => 'approved']);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->clover_payment_id)->toBe('chg_123');
    expect($payment->paid_at)->not->toBeNull();

    $attempt = CloverChargeAttempt::where('payment_id', $payment->id)->sole();
    expect($attempt->status)->toBe('approved');
    expect($attempt->clover_charge_id)->toBe('chg_123');
});

// Cross-check gap flagged during live AC-10 verification, 2026-09-18: unlike
// Stripe (PaymentIntent metadata) and Revolut (merchant_order_data.metadata),
// Clover's charge request never carried PayHub's own reference code, so an
// operator looking at a transaction in Clover's own merchant dashboard had no
// way to match it back to a PayHub payment. Clover's /v1/charges documents a
// metadata object for exactly this purpose (confirmed live against the
// sandbox — the field isn't rejected as malformed).
it('sends the reference code and payment uuid as clover metadata for cross-checking', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    fakeCloverCharge(['id' => 'chg_meta', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd']);

    chargeClover($payment->uuid)->assertOk();

    Http::assertSent(function ($request) use ($payment) {
        $metadata = $request['metadata'] ?? null;

        return is_array($metadata)
            && ($metadata['reference_code'] ?? null) === $payment->formattedReferenceCode()
            && ($metadata['payment_uuid'] ?? null) === $payment->uuid;
    });
});

// Charge metadata never shows in Clover's merchant dashboard, so the reference
// code is also sent as the charge description and written to the order note.
it('sends the reference code as the clover charge description', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    fakeCloverCharge(['id' => 'chg_desc', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd']);

    chargeClover($payment->uuid)->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://scl-sandbox.dev.clover.com/v1/charges'
        && $request['description'] === $payment->formattedReferenceCode());
});

it('writes the reference code to the clover order note on an approved charge', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    $merchantId = $payment->cloverAccount->merchant_id;
    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges' => Http::response(
            ['id' => 'chg_note', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd'], 200
        ),
        'scl-sandbox.dev.clover.com/v1/charges/*' => Http::response(
            ['id' => 'chg_note', 'order' => 'ORDNOTE1', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd'], 200
        ),
        'apisandbox.dev.clover.com/*' => Http::response(['id' => 'ORDNOTE1'], 200),
    ]);

    chargeClover($payment->uuid)->assertOk()->assertJson(['outcome' => 'approved']);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === "https://apisandbox.dev.clover.com/v3/merchants/{$merchantId}/orders/ORDNOTE1"
        && $request['note'] === $payment->formattedReferenceCode());
});

it('still completes the payment if writing the clover order note fails', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges' => Http::response(
            ['id' => 'chg_nf', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd'], 200
        ),
        'scl-sandbox.dev.clover.com/v1/charges/*' => Http::response(
            ['id' => 'chg_nf', 'order' => 'ORDNF1', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd'], 200
        ),
        'apisandbox.dev.clover.com/*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    chargeClover($payment->uuid)->assertOk()->assertJson(['outcome' => 'approved']);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->clover_payment_id)->toBe('ORDNF1');
});

it('does not write a clover order note when the charge is declined', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    fakeCloverCharge(['id' => 'chg_dec', 'order' => 'ORDDEC1', 'paid' => false, 'captured' => false, 'status' => 'failed', 'amount' => 5000, 'currency' => 'usd']);

    chargeClover($payment->uuid)->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orders/'));
});

// Regression (2026-09-18): live verification of AC-10 found the CSV export's
// "Provider Reference" (Clover's charge id) couldn't be found by searching
// Clover's own merchant dashboard, even though the same value was visible in
// the transaction's own detail page — confirmed by the engineer (who has
// dashboard access) that Clover's "order" id is what's actually searchable
// there. POST /v1/charges never returns "order" (confirmed live) — only a
// follow-up GET does, made only for an approved charge.
it('uses clover\'s order id, not the charge id, as the provider reference when available', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges' => Http::response(
            ['id' => 'chg_ord', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd'], 200
        ),
        'scl-sandbox.dev.clover.com/v1/charges/*' => Http::response(
            ['id' => 'chg_ord', 'order' => 'ORD123456', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd'], 200
        ),
    ]);

    chargeClover($payment->uuid)->assertOk()->assertJson(['outcome' => 'approved']);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->clover_payment_id)->toBe('ORD123456');

    // clover_charge_id (used internally for later Clover lookups) must stay
    // the real charge id, never the order id.
    expect(CloverChargeAttempt::where('payment_id', $payment->id)->sole()->clover_charge_id)->toBe('chg_ord');
});

// The order-id follow-up read is best-effort — the payment is already
// approved by the time it runs, so its failure must never break the response,
// only leave the provider reference as the charge id instead of the order id.
it('falls back to the charge id as the provider reference if the order-id lookup fails', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges' => Http::response(
            ['id' => 'chg_fallback', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd'], 200
        ),
        'scl-sandbox.dev.clover.com/v1/charges/*' => Http::response([], 500),
    ]);

    chargeClover($payment->uuid)->assertOk()->assertJson(['outcome' => 'approved']);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->clover_payment_id)->toBe('chg_fallback');
});

// AC-7: paid true but captured false must never be treated as approved.
it('does not complete the payment when captured is false', function () {
    // Prevents the dispatched resolver job from also running inline (this test's
    // QUEUE_CONNECTION is 'sync') — this test only covers the synchronous
    // handler's own classification, not the resolver job's separate behavior
    // (that's ResolveCloverChargeAttemptTest.php).
    Queue::fake();
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
    fakeCloverCharge(['id' => 'chg_uncaptured', 'paid' => true, 'captured' => false, 'amount' => 5000, 'currency' => 'usd']);

    chargeClover($payment->uuid)
        ->assertOk()
        ->assertJson(['outcome' => 'unknown']);

    expect($payment->refresh()->status)->toBe('pending');
});

// AC-5, AC-6, AC-8, AC-9: decline moves the payment to failed but is not terminal —
// a later approved attempt on the same payment still completes it.
it('allows a retry after a decline to still complete the payment', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 2500, 'currency' => 'usd']);

    // A later Http::fake() call does not replace an earlier one (both stubs stay
    // registered and the first-added match wins) — a sequence is required to
    // return different responses across two calls within one test.
    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges' => Http::sequence()
            ->push(['id' => 'chg_declined', 'paid' => false, 'decline_code' => 'card_declined'], 402)
            ->push(['id' => 'chg_approved', 'paid' => true, 'captured' => true, 'amount' => 2500, 'currency' => 'usd'], 200),
        // The second (approved) attempt triggers a follow-up GET for the order id.
        'scl-sandbox.dev.clover.com/v1/charges/*' => Http::response(
            ['id' => 'chg_approved', 'paid' => true, 'captured' => true, 'amount' => 2500, 'currency' => 'usd'], 200
        ),
    ]);

    chargeClover($payment->uuid)->assertOk()->assertJson(['outcome' => 'declined']);
    expect($payment->refresh()->status)->toBe('failed');

    chargeClover($payment->uuid)->assertOk()->assertJson(['outcome' => 'approved']);
    expect($payment->refresh()->status)->toBe('completed');

    expect(CloverChargeAttempt::where('payment_id', $payment->id)->count())->toBe(2);
});

// The 2026-09-17 cross check's fix: a decline delivered as an HTTP 4xx must
// still classify as declined, not fall into the transport-failed/unknown bucket.
it('classifies a decline delivered as a 4xx correctly, not as unknown', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    fakeCloverCharge(['id' => 'chg_402', 'error' => ['type' => 'card_error', 'code' => 'card_declined']], 402);

    chargeClover($payment->uuid)
        ->assertOk()
        ->assertJson(['outcome' => 'declined']);

    expect($payment->refresh()->status)->toBe('failed');
});

// AC-5: a hard error (bad credentials, malformed request) never retries and
// never touches the payment's status.
it('marks a hard error attempt without touching the payment status', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    fakeCloverCharge(['message' => 'invalid merchant'], 401);

    chargeClover($payment->uuid)->assertStatus(502);

    expect($payment->refresh()->status)->toBe('pending');
    expect(CloverChargeAttempt::where('payment_id', $payment->id)->sole()->status)->toBe('hard_error');
});

// Regression (2026-09-18): live verification of AC-9 hit "Your card was declined"
// on every real sandbox submit. Root cause: PayHub sent its 36-char UUID
// idempotency key as external_reference_id, which Clover caps at 12 characters
// and rejects with {"error":{"type":"invalid_request_error",...}} — a request
// PayHub itself malformed, not a card decline — and the classifier read any
// body with an "error" key as a decline, hiding the real problem. Fixed by
// generating a short key and only treating card_error as a decline.
it('sends a short (<=12 char) external_reference_id, satisfying clover\'s field limit', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    fakeCloverCharge(['id' => 'chg_ref', 'paid' => true, 'captured' => true, 'amount' => $payment->amount, 'currency' => $payment->currency]);

    chargeClover($payment->uuid)->assertOk();

    Http::assertSent(function ($request) {
        $externalReferenceId = $request['external_reference_id'] ?? null;

        return is_string($externalReferenceId) && strlen($externalReferenceId) <= 12;
    });
});

// Regression (2026-09-18): live verification with Clover's own documented
// decline test card left the client stuck on "Confirming your payment…"
// forever. Clover's real decline response (captured directly against the
// sandbox) has no top-level "paid" and a camelCase "declineCode", neither of
// which the classifier recognized, so it fell into "unknown" instead of
// "declined" — the payment never resolved because there was nothing for the
// resolver job to find either (a real decline has no charge id to retry with
// via getCharge(), only error.charge, which wasn't read at all).
it('resolves clover\'s real decline shape synchronously instead of leaving the payment stuck pending', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 100000, 'currency' => 'usd']);
    fakeCloverCharge([
        'message' => '402 Payment Required',
        'error' => [
            'code' => 'card_declined',
            'message' => 'DECLINED: No reason provided.',
            'charge' => '0DZ07JZP5XSVT',
            'declineCode' => 'issuer_declined',
        ],
    ], 402);

    chargeClover($payment->uuid)
        ->assertOk()
        ->assertJson(['outcome' => 'declined']);

    expect($payment->refresh()->status)->toBe('failed');

    $attempt = CloverChargeAttempt::where('payment_id', $payment->id)->sole();
    expect($attempt->status)->toBe('declined');
    expect($attempt->clover_charge_id)->toBe('0DZ07JZP5XSVT');
    expect($attempt->decline_reason)->toBe('issuer_declined');
});

it('classifies an invalid_request_error as a hard error, never as a card decline', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    fakeCloverCharge(['message' => '400 Bad Request', 'error' => [
        'type' => 'invalid_request_error',
        'code' => 'invalid_request',
        'message' => 'Please provide valid format or check allowable max length of 12.',
    ]], 400);

    chargeClover($payment->uuid)->assertStatus(502);

    expect($payment->refresh()->status)->toBe('pending');
    expect(CloverChargeAttempt::where('payment_id', $payment->id)->sole()->status)->toBe('hard_error');
});

// AC-5, AC-8: a transport failure (timeout/connection error) is classified
// unknown, leaves the payment untouched, and dispatches the resolver job.
it('dispatches the resolver job when the charge response is unknown', function () {
    Queue::fake();
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    Http::fake(['scl-sandbox.dev.clover.com/v1/charges' => fn () => throw new ConnectionException('timed out')]);

    chargeClover($payment->uuid)
        ->assertOk()
        ->assertJson(['outcome' => 'unknown']);

    expect($payment->refresh()->status)->toBe('pending');
    Queue::assertPushed(ResolveCloverChargeAttempt::class);
});

// AC-5: precondition guard rejects before any Clover call is made.
it('rejects a charge against an already-completed payment before calling clover', function () {
    Http::fake();
    $payment = Payment::factory()->clover()->create(['status' => 'completed']);

    chargeClover($payment->uuid)->assertStatus(422);

    Http::assertNothingSent();
});

it('rejects a charge against a non-clover payment', function () {
    Http::fake();
    $payment = Payment::factory()->create(['status' => 'pending']); // stripe provider

    chargeClover($payment->uuid)->assertStatus(422);

    Http::assertNothingSent();
});

it('rejects a charge against an inactive clover account', function () {
    Http::fake();
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    $payment->cloverAccount->update(['is_active' => false]);

    chargeClover($payment->uuid)->assertStatus(422);

    Http::assertNothingSent();
});

it('requires a token', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);

    chargeClover($payment->uuid, [])->assertStatus(422);
});

// AC-5, Key invariants: at most one charge attempt is ever in flight per payment.
it('rejects a concurrent charge attempt while one is already in flight', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);

    $lock = Cache::lock("clover-charge-attempt:{$payment->id}", 30);
    expect($lock->get())->toBeTrue();

    try {
        chargeClover($payment->uuid)->assertStatus(409);
    } finally {
        $lock->release();
    }
});

// AC-11: rate limited to 10 attempts per payment per hour, keyed on the payment's
// UUID rather than the default IP throttle.
it('rate limits charge attempts to 10 per hour per payment', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    fakeCloverCharge(['id' => 'chg_x', 'paid' => false, 'decline_code' => 'card_declined'], 402);

    for ($i = 0; $i < 10; $i++) {
        chargeClover($payment->uuid)->assertOk();
    }

    chargeClover($payment->uuid)->assertStatus(429);

    Http::assertSentCount(10);
});

// Regression (2026-09-18): live verification of the double-click/concurrency
// case showed "Too many attempts" on a brand-new payment with zero prior
// attempts. Root cause: `$request->route('payment')` inside the RateLimiter::for
// closure returns the raw route-parameter string, not a bound Payment model —
// route/model binding hasn't resolved yet at that point in the middleware
// pipeline (confirmed live by logging its type: "string", not "App\Models\Payment").
// `$routeParam?->uuid` on a string silently evaluates to null, so the limiter
// fell through to `?? $request->ip()` on every single request — it was IP-keyed
// for every client the whole time, exactly the "not the default IP keyed
// throttle" AC-11 says to avoid. One payment's testing could exhaust another,
// unrelated payment's rate limit. This test uses two real requests (not
// Http::fake's request-count-only view) specifically to catch that
// cross-payment leak, which the single-payment test above can't: with only one
// payment and one test client (one IP), IP-keying and UUID-keying are
// indistinguishable.
it('does not let one payment\'s rate limit affect a different payment (regression: was IP-keyed, not UUID-keyed)', function () {
    $paymentA = Payment::factory()->clover()->create(['status' => 'pending']);
    $paymentB = Payment::factory()->clover()->create(['status' => 'pending']);
    fakeCloverCharge(['id' => 'chg_x', 'paid' => false, 'decline_code' => 'card_declined'], 402);

    for ($i = 0; $i < 10; $i++) {
        chargeClover($paymentA->uuid)->assertOk();
    }
    chargeClover($paymentA->uuid)->assertStatus(429);

    // Payment B has made zero attempts of its own — it must not be throttled
    // just because payment A (a different payment, but the same test client/IP)
    // already exhausted its limit.
    chargeClover($paymentB->uuid)->assertOk();
});

// AC-11: the client-facing message is always a fixed PayHub string, never
// Clover's raw decline text.
it('never surfaces clover raw decline text to the client', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    fakeCloverCharge(['id' => 'chg_raw', 'paid' => false, 'decline_code' => 'insufficient_funds', 'error' => 'Your card has insufficient funds, contact your bank at 1-800-RAW-LEAK'], 402);

    $response = chargeClover($payment->uuid)->assertOk();

    expect($response->json('message'))->not->toContain('RAW-LEAK');

    $attempt = CloverChargeAttempt::where('payment_id', $payment->id)->sole();
    expect($attempt->decline_reason)->toContain('insufficient_funds');
});

it('clover charge route is CSRF-excluded', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    fakeCloverCharge(['id' => 'chg_csrf', 'paid' => true, 'captured' => true, 'amount' => $payment->amount, 'currency' => $payment->currency]);

    test()->call('POST', "/pay/{$payment->uuid}/clover/charge", [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode(['token' => 'clv_test_token']))
        ->assertOk();
});

it('reports the payment status via the status poll endpoint', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);

    test()->getJson("/pay/{$payment->uuid}/clover/status")
        ->assertOk()
        ->assertJson(['status' => 'pending']);
});

it('does not expose the clover private token on the pay page', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    Http::fake();

    test()->get("/pay/{$payment->uuid}")
        ->assertInertia(fn ($page) => $page
            ->component('ClientPayment/PayClover')
            ->has('cloverAccount.api_access_key')
            ->missing('cloverAccount.private_token')
        );
});
