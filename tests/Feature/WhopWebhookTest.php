<?php

use App\Jobs\HandleWhopWebhookJob;
use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Models\ProcessedWhopEvent;
use App\Models\WhopAccount;
use App\Services\Whop\WhopAmount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const WHOP_SECRET = 'ws_test_secret_value';

/** Standard Webhooks signature: base64 HMAC-SHA256 over "{id}.{timestamp}.{body}". */
function whopSignature(string $id, string $timestamp, string $payload, string $secret = WHOP_SECRET): string
{
    return 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$payload, $secret, true));
}

function whopPost(WhopAccount $account, string $payload, ?string $id = 'msg_1', ?string $signature = null, ?string $timestamp = null): TestResponse
{
    $timestamp ??= (string) time();
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($id !== null) {
        $server['HTTP_WEBHOOK_ID'] = $id;
        $server['HTTP_WEBHOOK_TIMESTAMP'] = $timestamp;
        $server['HTTP_WEBHOOK_SIGNATURE'] = $signature ?? whopSignature($id, $timestamp, $payload);
    }

    return test()->call('POST', "/webhook/whop/{$account->id}", [], [], [], $server, $payload);
}

function whopSucceeded(Payment $payment, array $overrides = []): string
{
    return json_encode([
        'type' => 'payment.succeeded',
        'data' => array_merge([
            'id' => 'pay_abc123',
            'subtotal' => WhopAmount::centsToDecimal($payment->amount),
            'currency' => strtoupper($payment->currency),
            'paid_at' => '2026-11-02T10:00:00Z',
            'metadata' => ['payment_id' => $payment->uuid, 'reference' => 'x'],
        ], $overrides),
    ]);
}

function whopPayment(array $attrs = []): array
{
    $account = WhopAccount::factory()->create(['webhook_secret' => WHOP_SECRET]);
    $payment = Payment::factory()->whop()->create(array_merge([
        'whop_account_id' => $account->id,
        'amount' => 1234,
        'currency' => 'gbp',
        'status' => 'pending',
    ], $attrs));

    return [$account, $payment];
}

function runWhopJob(WhopAccount $account, string $payload, string $id = 'msg_1'): void
{
    (new HandleWhopWebhookJob($account->id, $id, $payload))->handle();
}

// ---- Signature route (AC-9) ----

it('queues the job and returns 200 for a good signature', function () {
    Queue::fake();
    [$account, $payment] = whopPayment();

    whopPost($account, whopSucceeded($payment))->assertOk();

    Queue::assertPushed(HandleWhopWebhookJob::class);
});

it('returns 404 for an unknown account', function () {
    $this->post('/webhook/whop/99999')->assertNotFound();
});

it('returns 403 for an account with no stored secret', function () {
    Queue::fake();
    $account = WhopAccount::factory()->create(['webhook_secret' => null]);

    whopPost($account, '{}')->assertForbidden();

    Queue::assertNothingPushed();
});

it('returns 401 and queues nothing for bad, missing or stale signatures', function (string $case) {
    Queue::fake();
    [$account] = whopPayment();
    $payload = '{"type":"payment.succeeded"}';

    $response = match ($case) {
        'bad' => whopPost($account, $payload, 'msg_1', 'v1,'.base64_encode('wrong')),
        'missing' => whopPost($account, $payload, null),
        'stale' => whopPost($account, $payload, 'msg_1', null, (string) (time() - 600)),
        'wrong secret' => whopPost($account, $payload, 'msg_1', whopSignature('msg_1', (string) time(), $payload, 'other')),
    };

    $response->assertUnauthorized();
    Queue::assertNothingPushed();
})->with(['bad', 'missing', 'stale', 'wrong secret']);

it('accepts a header with two signatures when only one matches', function () {
    Queue::fake();
    [$account, $payment] = whopPayment();
    $payload = whopSucceeded($payment);
    $ts = (string) time();
    $header = 'v1,'.base64_encode('nope').' '.whopSignature('msg_1', $ts, $payload);

    whopPost($account, $payload, 'msg_1', $header, $ts)->assertOk();
});

it('accepts a base64 encoded secret after the ws_ prefix', function () {
    Queue::fake();
    $raw = random_bytes(24);
    $account = WhopAccount::factory()->create(['webhook_secret' => 'ws_'.base64_encode($raw)]);
    $payload = '{"type":"payment.failed"}';
    $ts = (string) time();

    whopPost($account, $payload, 'msg_9', whopSignature('msg_9', $ts, $payload, $raw), $ts)->assertOk();
});

it('still processes an inactive account', function () {
    Queue::fake();
    [$account, $payment] = whopPayment();
    $account->update(['is_active' => false]);

    whopPost($account, whopSucceeded($payment))->assertOk();

    Queue::assertPushed(HandleWhopWebhookJob::class);
});

// ---- Completion rules (AC-10) ----

it('completes a pending payment once and sends one notification', function () {
    Queue::fake();
    [$account, $payment] = whopPayment();
    Cache::put('whop:pause:'.$payment->id, true, 600);

    runWhopJob($account, whopSucceeded($payment));

    $payment->refresh();
    expect($payment->status)->toBe('completed')
        ->and($payment->whop_payment_id)->toBe('pay_abc123')
        ->and($payment->paid_at->format('Y-m-d H:i'))->toBe('2026-11-02 10:00')
        ->and(Cache::has('whop:pause:'.$payment->id))->toBeFalse()
        ->and(ProcessedWhopEvent::where('webhook_id', 'msg_1')->count())->toBe(1);
    Queue::assertPushed(SendPaymentNotification::class, 1);
});

it('does nothing the second time for the same webhook id', function () {
    Queue::fake();
    [$account, $payment] = whopPayment();

    runWhopJob($account, whopSucceeded($payment));
    Payment::whereKey($payment->id)->update(['status' => 'pending']);
    runWhopJob($account, whopSucceeded($payment));

    expect($payment->fresh()->status)->toBe('pending');
    Queue::assertPushed(SendPaymentNotification::class, 1);
});

it('can complete a failed payment', function () {
    Queue::fake();
    [$account, $payment] = whopPayment(['status' => 'failed']);

    runWhopJob($account, whopSucceeded($payment));

    expect($payment->fresh()->status)->toBe('completed');
});

it('leaves the payment alone and logs an error on a mismatch', function (array $override) {
    Queue::fake();
    Log::spy();
    [$account, $payment] = whopPayment();

    runWhopJob($account, whopSucceeded($payment, $override));

    expect($payment->fresh()->status)->toBe('pending');
    Log::shouldHaveReceived('error')->once();
    Queue::assertNotPushed(SendPaymentNotification::class);
})->with([
    'wrong amount' => [['subtotal' => '12.35']],
    'wrong currency' => [['currency' => 'USD']],
    'missing amount' => [['subtotal' => null]],
    'exponent amount' => [['subtotal' => '1.234E1']],
    'unknown uuid' => [['metadata' => ['payment_id' => '00000000-0000-0000-0000-000000000000']]],
]);

it('refuses a payment that belongs to another Whop account', function () {
    Queue::fake();
    Log::spy();
    [, $payment] = whopPayment();
    $other = WhopAccount::factory()->create(['webhook_secret' => WHOP_SECRET]);

    runWhopJob($other, whopSucceeded($payment));

    expect($payment->fresh()->status)->toBe('pending');
    Log::shouldHaveReceived('error')->once();
});

it('ignores an event with no payment_id metadata at info level', function () {
    Queue::fake();
    Log::spy();
    [$account, $payment] = whopPayment();

    runWhopJob($account, whopSucceeded($payment, ['metadata' => []]));

    expect($payment->fresh()->status)->toBe('pending');
    Log::shouldHaveReceived('info');
    Log::shouldNotHaveReceived('error');
});

it('logs an error for a cancelled payment and for a second success with another Whop id', function () {
    Queue::fake();
    Log::spy();
    [$account, $cancelled] = whopPayment(['status' => 'cancelled']);
    runWhopJob($account, whopSucceeded($cancelled), 'msg_a');
    expect($cancelled->fresh()->status)->toBe('cancelled');

    [$account2, $done] = whopPayment(['status' => 'completed', 'whop_payment_id' => 'pay_first']);
    runWhopJob($account2, whopSucceeded($done, ['id' => 'pay_second']), 'msg_b');
    expect($done->fresh()->whop_payment_id)->toBe('pay_first');

    Log::shouldHaveReceived('error')->twice();
});

it('records no event row if completion throws', function () {
    [$account, $payment] = whopPayment();
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'update `payments`') || str_starts_with($query->sql, 'update "payments"')) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => runWhopJob($account, whopSucceeded($payment)))->toThrow(RuntimeException::class);
    expect(ProcessedWhopEvent::count())->toBe(0);
});

// ---- Other events (AC-11) ----

it('logs payment.failed and refunds at info level with no status change', function (string $type) {
    Queue::fake();
    [$account, $payment] = whopPayment();

    runWhopJob($account, json_encode(['type' => $type, 'data' => ['metadata' => ['payment_id' => $payment->uuid]]]));

    expect($payment->fresh()->status)->toBe('pending')
        ->and(ProcessedWhopEvent::count())->toBe(1);
})->with(['payment.failed', 'refund.created', 'dispute.created']);

// ---- Amount helper (AC-5, AC-10) ----

it('converts amounts exactly in both directions', function () {
    foreach ([0, 1, 10, 1234, 99, 100000, 12345678] as $cents) {
        expect(WhopAmount::decimalToCents(WhopAmount::centsToDecimal($cents)))->toBe($cents);
    }

    expect(WhopAmount::centsToDecimal(1234))->toBe('12.34')
        ->and(WhopAmount::centsToDecimal(10))->toBe('0.10')
        ->and(WhopAmount::decimalToCents('12.3'))->toBe(1230)
        ->and(WhopAmount::decimalToCents(12.34))->toBe(1234)
        ->and(WhopAmount::decimalToCents('12.3400'))->toBe(1234)
        ->and(WhopAmount::decimalToCents('12.345'))->toBeNull()
        ->and(WhopAmount::decimalToCents('-1.00'))->toBeNull()
        ->and(WhopAmount::decimalToCents('1e2'))->toBeNull()
        ->and(WhopAmount::decimalToCents(''))->toBeNull();
});
