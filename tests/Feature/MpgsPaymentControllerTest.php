<?php

use App\Jobs\ResolveMpgsChargeAttempt;
use App\Jobs\SendPaymentNotification;
use App\Models\MpgsAccount;
use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    // An approved PAY dispatches SendPaymentNotification, which looks up the admin role.
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function mpgsPayable(array $payment = [], array $account = []): Payment
{
    $acct = MpgsAccount::factory()->create($account + ['gateway_host' => 'https://test-gateway.example.com']);

    return Payment::factory()->mpgs()->create($payment + [
        'mpgs_account_id' => $acct->id,
        'status' => 'pending',
        'amount' => 1500,
        'currency' => 'usd',
    ]);
}

function mpgsPayApprovedBody(string $amount = '15.00', string $currency = 'USD'): array
{
    return [
        'result' => 'SUCCESS',
        'response' => ['gatewayCode' => 'APPROVED'],
        'order' => ['status' => 'CAPTURED', 'totalCapturedAmount' => $amount, 'currency' => $currency],
        'transaction' => ['receipt' => '123456'],
    ];
}

function mpgsFakeSession(string $sessionId = 'SESSION0001'): void
{
    Http::fake([
        '*/session' => Http::response(['result' => 'SUCCESS', 'session' => ['id' => $sessionId]], 201),
        '*/session/*' => Http::response(['result' => 'SUCCESS'], 200),
    ]);
}

// ── prepare (session) ────────────────────────────────────────────────────────

it('prepares a session and records an attempt with a unique order id', function () {
    mpgsFakeSession();
    $payment = mpgsPayable();

    $this->postJson(route('pay.mpgs.session', $payment))
        ->assertOk()
        ->assertJsonStructure(['session_id', 'order_id', 'auth_transaction_id', 'attempt_id'])
        ->assertJsonPath('session_id', 'SESSION0001')
        ->assertJsonPath('order_id', "{$payment->id}-1");

    $attempt = $payment->mpgsChargeAttempts()->first();
    expect($attempt->status)->toBe('prepared')
        ->and($attempt->session_id)->toBe('SESSION0001');

    // The amount written onto the session comes from the Payment record.
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request['order']['amount'] === '15.00'
        && $request['order']['currency'] === 'USD'
        && $request['authentication']['channel'] === 'PAYER_BROWSER');
});

it('never stores the session id in plain text', function () {
    mpgsFakeSession();
    $payment = mpgsPayable();

    $this->postJson(route('pay.mpgs.session', $payment))->assertOk();

    $raw = DB::table('mpgs_charge_attempts')->value('session_id');
    expect($raw)->not->toBe('SESSION0001')->and($raw)->not->toBeNull();
});

it('retires the earlier prepared attempt and issues order id -2 on a second prepare', function () {
    mpgsFakeSession();
    $payment = mpgsPayable();

    $this->postJson(route('pay.mpgs.session', $payment))->assertOk();
    $this->postJson(route('pay.mpgs.session', $payment))->assertOk()
        ->assertJsonPath('order_id', "{$payment->id}-2");

    $statuses = $payment->mpgsChargeAttempts()->orderBy('sequence')->pluck('status')->all();
    expect($statuses)->toBe(['abandoned', 'prepared']);
    expect($payment->mpgsChargeAttempts()->where('status', 'abandoned')->first()->session_id)->toBeNull();
});

it('refuses to prepare when the payment cannot be paid', function (string $case) {
    Http::fake();
    $payment = match ($case) {
        'completed' => mpgsPayable(['status' => 'completed']),
        'inactive account' => mpgsPayable(account: ['is_active' => false]),
        'other provider' => Payment::factory()->create(),
    };

    $this->postJson(route('pay.mpgs.session', $payment))->assertStatus(422);

    Http::assertNothingSent();
    expect(MpgsChargeAttempt::count())->toBe(0);
})->with(['completed', 'inactive account', 'other provider']);

it('returns 409 when a pay is already in flight', function () {
    Http::fake();
    $payment = mpgsPayable();
    MpgsChargeAttempt::factory()->pending()->create(['payment_id' => $payment->id]);

    $this->postJson(route('pay.mpgs.session', $payment))->assertStatus(409);

    Http::assertNothingSent();
});

it('answers 502 with a fixed message when the gateway refuses the session', function () {
    Http::fake(['*' => Http::response(['error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'secret detail']], 400)]);
    $payment = mpgsPayable();

    $this->postJson(route('pay.mpgs.session', $payment))
        ->assertStatus(502)
        ->assertJsonMissing(['explanation' => 'secret detail']);

    expect(MpgsChargeAttempt::count())->toBe(0);
});

// ── pay ──────────────────────────────────────────────────────────────────────

it('completes the payment on an approved PAY and notifies once', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(mpgsPayApprovedBody(), 200)]);
    $payment = mpgsPayable();
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]);

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id])
        ->assertOk()
        ->assertJsonPath('outcome', 'approved');

    expect($payment->refresh()->status)->toBe('completed')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->mpgs_order_id)->toBe($attempt->order_id)
        ->and($attempt->refresh()->status)->toBe('approved')
        ->and($attempt->session_id)->toBeNull();

    Queue::assertPushed(SendPaymentNotification::class, 1);
});

it('sends the amount and currency from the Payment, never the request', function () {
    Http::fake(['*' => Http::response(mpgsPayApprovedBody(), 200)]);
    $payment = mpgsPayable();
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]);

    $this->postJson(route('pay.mpgs.pay', $payment), [
        'attempt_id' => $attempt->id,
        'amount' => 1,
        'currency' => 'GBP',
    ])->assertOk();

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request['apiOperation'] === 'PAY'
        && $request['order']['amount'] === '15.00'
        && $request['order']['currency'] === 'USD'
        && $request['session']['id'] === $attempt->session_id);
});

it('marks a declined PAY as failed and lets the client retry on a new attempt', function () {
    Queue::fake();
    $payment = mpgsPayable();
    $first = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id, 'sequence' => 1]);

    // Stubs are matched first-come, so one fake serves the whole flow: the first
    // PAY is declined, the second approved.
    Http::fake([
        '*/session' => Http::response(['result' => 'SUCCESS', 'session' => ['id' => 'SESSION0002']], 201),
        '*/session/*' => Http::response(['result' => 'SUCCESS'], 200),
        '*/order/*' => Http::sequence()
            ->push(['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED']], 200)
            ->push(mpgsPayApprovedBody(), 200),
    ]);

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $first->id])
        ->assertOk()
        ->assertJsonPath('outcome', 'declined')
        ->assertJsonPath('message', 'Your card was declined. Please try a different card.');

    expect($payment->refresh()->status)->toBe('failed')
        ->and($first->refresh()->status)->toBe('declined');

    // Retry: a new prepare gets order id -2, and its approved PAY completes the payment.
    $second = $this->postJson(route('pay.mpgs.session', $payment))->assertOk()->json();
    expect($second['order_id'])->toBe("{$payment->id}-2");

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $second['attempt_id']])
        ->assertOk()->assertJsonPath('outcome', 'approved');

    expect($payment->refresh()->status)->toBe('completed');
});

it('treats a decline delivered as HTTP 4xx as declined, not a hard error', function () {
    Http::fake(['*' => Http::response(['result' => 'FAILURE', 'response' => ['gatewayCode' => 'INSUFFICIENT_FUNDS']], 400)]);
    $payment = mpgsPayable();
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]);

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id])
        ->assertOk()->assertJsonPath('outcome', 'declined');

    expect($attempt->refresh()->status)->toBe('declined');
});

it('treats a gateway rejection of the request as a hard error with a fixed message and an untouched payment', function () {
    Http::fake(['*' => Http::response([
        'result' => 'ERROR',
        'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Currency not enabled for merchant'],
    ], 400)]);
    $payment = mpgsPayable();
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]);

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id])
        ->assertStatus(502)
        ->assertJsonPath('error', 'Your payment could not be processed. Please try again or use a different card.');

    expect($payment->refresh()->status)->toBe('pending')
        ->and($attempt->refresh()->status)->toBe('hard_error');
});

it('never approves a success whose amount does not match, and queues the resolver instead', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(mpgsPayApprovedBody('1.00'), 200)]);
    $payment = mpgsPayable();
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]);

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id])
        ->assertOk()->assertJsonPath('outcome', 'unknown');

    expect($payment->refresh()->status)->toBe('pending')
        ->and($attempt->refresh()->status)->toBe('pending');

    Queue::assertPushed(ResolveMpgsChargeAttempt::class, fn ($job) => $job->attemptId === $attempt->id);
    Queue::assertNotPushed(SendPaymentNotification::class);
});

it('treats a timeout as unknown and queues the read only resolver', function () {
    Queue::fake();
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timed out'));
    $payment = mpgsPayable();
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]);

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id])
        ->assertOk()->assertJsonPath('outcome', 'unknown');

    expect($payment->refresh()->status)->toBe('pending')
        ->and($attempt->refresh()->status)->toBe('pending');
    Queue::assertPushed(ResolveMpgsChargeAttempt::class);
});

it('rejects a pay for an unusable attempt without calling the gateway', function (string $case) {
    Http::fake();
    $payment = mpgsPayable();
    $other = mpgsPayable();

    $attemptId = match ($case) {
        'already pending' => MpgsChargeAttempt::factory()->pending()->create(['payment_id' => $payment->id])->id,
        'already approved' => MpgsChargeAttempt::factory()->create(['payment_id' => $payment->id])->id,
        'another payments attempt' => MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $other->id])->id,
        'expired' => tap(MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]),
            fn ($a) => $a->forceFill(['created_at' => now()->subHours(2)])->save())->id,
        'missing' => 999999,
    };

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attemptId])->assertStatus(422);

    Http::assertNothingSent();
})->with(['already pending', 'already approved', 'another payments attempt', 'expired', 'missing']);

it('rejects a pay on a completed payment or an inactive account', function () {
    Http::fake();
    $done = mpgsPayable(['status' => 'completed']);
    $off = mpgsPayable(account: ['is_active' => false]);
    $a1 = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $done->id]);
    $a2 = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $off->id]);

    $this->postJson(route('pay.mpgs.pay', $done), ['attempt_id' => $a1->id])->assertStatus(422);
    $this->postJson(route('pay.mpgs.pay', $off), ['attempt_id' => $a2->id])->assertStatus(422);

    Http::assertNothingSent();
});

it('answers 409 and sends nothing when another pay holds the lock', function () {
    Http::fake();
    $payment = mpgsPayable();
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]);

    $held = Cache::lock("mpgs-attempt:{$payment->id}", 60);
    expect($held->get())->toBeTrue();

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id])->assertStatus(409);

    Http::assertNothingSent();
    expect($attempt->refresh()->status)->toBe('prepared');
    $held->release();
});

it('requires an attempt id', function () {
    $payment = mpgsPayable();

    $this->postJson(route('pay.mpgs.pay', $payment), [])->assertStatus(422)->assertJsonValidationErrors('attempt_id');
});

it('never returns the raw gateway decline text to the client', function () {
    Http::fake(['*' => Http::response([
        'result' => 'FAILURE',
        'response' => ['gatewayCode' => 'DECLINED', 'acquirerMessage' => 'Do not honour 05 SENSITIVE'],
    ], 200)]);
    $payment = mpgsPayable();
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id]);

    $response = $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id])->assertOk();

    expect($response->getContent())->not->toContain('SENSITIVE')->not->toContain('honour');
});

// ── rate limits ──────────────────────────────────────────────────────────────

it('rate limits pay to 10 per payment per hour with no gateway call on the 11th', function () {
    Http::fake();
    RateLimiter::clear('mpgs-pay');
    $payment = mpgsPayable();

    foreach (range(1, 10) as $i) {
        $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => 999999])->assertStatus(422);
    }

    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => 999999])->assertStatus(429);
    Http::assertNothingSent();
});

it('rate limits session prepare to 20 per payment per hour', function () {
    mpgsFakeSession();
    $payment = mpgsPayable();

    foreach (range(1, 20) as $i) {
        $this->postJson(route('pay.mpgs.session', $payment))->assertOk();
    }

    $this->postJson(route('pay.mpgs.session', $payment))->assertStatus(429);
});

it('keys the rate limit on the payment, so another payment is unaffected', function () {
    Http::fake();
    $a = mpgsPayable();
    $b = mpgsPayable();

    foreach (range(1, 11) as $i) {
        $this->postJson(route('pay.mpgs.pay', $a), ['attempt_id' => 999999]);
    }

    $this->postJson(route('pay.mpgs.pay', $b), ['attempt_id' => 999999])->assertStatus(422);
});

// ── status poll ──────────────────────────────────────────────────────────────

it('reports the payment status read only and 404s a non MPGS payment', function () {
    $payment = mpgsPayable(['status' => 'completed']);

    $this->getJson(route('pay.mpgs.status', $payment))->assertOk()->assertExactJson(['status' => 'completed']);
    $this->getJson(route('pay.mpgs.status', Payment::factory()->create()))->assertNotFound();
});

// ── 3DS return route ─────────────────────────────────────────────────────────

it('serves the 3DS return page framable by PayHub itself and changes nothing', function () {
    $payment = mpgsPayable();

    $this->post(route('pay.mpgs.return', $payment), ['orderId' => 'x', 'result' => 'SUCCESS', 'gatewayRecommendation' => 'PROCEED'])
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    $this->get(route('pay.mpgs.return', $payment))->assertOk();
    expect($payment->refresh()->status)->toBe('pending');
    expect(MpgsChargeAttempt::count())->toBe(0);
});

it('escapes what the bank posts back to the return page', function () {
    $payment = mpgsPayable();

    $response = $this->post(route('pay.mpgs.return', $payment), ['orderId' => '"><script>alert(1)</script>']);

    expect($response->getContent())->not->toContain('<script>alert(1)</script>');
});

it('404s the return route for a non MPGS payment', function () {
    $this->get(route('pay.mpgs.return', Payment::factory()->create()))->assertNotFound();
});

it('keeps X-Frame-Options DENY on every other route', function () {
    $payment = mpgsPayable();

    $this->getJson(route('pay.mpgs.status', $payment))->assertHeader('X-Frame-Options', 'DENY');
});

// ── CSP ──────────────────────────────────────────────────────────────────────

it('widens the CSP only on the pay page of an MPGS payment', function () {
    $mpgs = mpgsPayable();

    $csp = $this->get(route('pay.show', $mpgs))->headers->get('Content-Security-Policy');

    expect($csp)->toContain('https://test-gateway.example.com')
        ->and($csp)->toMatch('/frame-src [^;]*\shttps:(;|\s)/');
});

it('keeps the strict CSP on a non MPGS pay page and on other routes', function () {
    $other = Payment::factory()->create();
    $mpgs = mpgsPayable();

    $strictPay = $this->get(route('pay.show', $other))->headers->get('Content-Security-Policy');
    $strictOther = $this->getJson(route('pay.mpgs.status', $mpgs))->headers->get('Content-Security-Policy');

    expect($strictPay)->not->toContain('test-gateway.example.com')->not->toMatch('/frame-src [^;]*\shttps:(;|\s)/');
    expect($strictOther)->not->toContain('test-gateway.example.com')->not->toMatch('/frame-src [^;]*\shttps:(;|\s)/');
});

// ── secrets ──────────────────────────────────────────────────────────────────

it('does not put the integration password or session id on the pay page props', function () {
    $payment = mpgsPayable();
    MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id, 'session_id' => 'SESSIONLEAK123']);

    $content = $this->get(route('pay.show', $payment))->getContent();

    expect($content)->not->toContain('placeholder_integration_password_for_dev_only')
        ->and($content)->not->toContain('SESSIONLEAK123');
});
