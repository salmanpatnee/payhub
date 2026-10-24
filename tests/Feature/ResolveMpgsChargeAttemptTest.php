<?php

use App\Jobs\ResolveMpgsChargeAttempt;
use App\Jobs\SendPaymentNotification;
use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use App\Services\Mpgs\MpgsStaleAttemptSweep;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

/**
 * @return array{0: Payment, 1: MpgsChargeAttempt}
 */
function mpgsResolvable(string $paymentStatus = 'pending'): array
{
    $payment = Payment::factory()->mpgs()->create(['status' => $paymentStatus, 'amount' => 1500, 'currency' => 'usd']);
    $attempt = MpgsChargeAttempt::factory()->pending()->create([
        'payment_id' => $payment->id,
        'transaction_id' => 'txn-pay',
    ]);

    return [$payment, $attempt];
}

function mpgsOrderBody(array $transactions, string $status = 'CAPTURED'): array
{
    return [
        'status' => $status,
        'totalCapturedAmount' => '15.00',
        'currency' => 'USD',
        'transaction' => $transactions,
    ];
}

function mpgsApprovedTransaction(string $id = 'txn-pay'): array
{
    return [
        'result' => 'SUCCESS',
        'response' => ['gatewayCode' => 'APPROVED'],
        'transaction' => ['id' => $id, 'type' => 'PAYMENT', 'receipt' => '999111'],
    ];
}

// AC-10: the resolver retrieves the order and never re-sends PAY.
it('resolves a pending attempt by reading the order, and only ever reads', function () {
    Queue::fake([SendPaymentNotification::class]);
    [$payment, $attempt] = mpgsResolvable();

    Http::fake(['*/order/*' => Http::response(mpgsOrderBody([mpgsApprovedTransaction()]), 200)]);

    (new ResolveMpgsChargeAttempt($attempt->id))->handle();

    expect($payment->refresh()->status)->toBe('completed');
    expect($payment->mpgs_order_id)->toBe($attempt->order_id);
    expect($attempt->refresh()->status)->toBe('approved');
    expect($attempt->session_id)->toBeNull();
    expect($attempt->receipt)->toBe('999111');
    Queue::assertPushed(SendPaymentNotification::class, 1);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), "/order/{$attempt->order_id}"));
});

// AC-10: pick the PAY transaction by its id, not "the last one".
it('picks the PAY transaction by id, not the last one in the list', function () {
    [$payment, $attempt] = mpgsResolvable();

    Http::fake(['*/order/*' => Http::response(mpgsOrderBody([
        mpgsApprovedTransaction('txn-pay'),
        ['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED'], 'transaction' => ['id' => 'txn-later', 'type' => 'PAYMENT']],
    ]), 200)]);

    (new ResolveMpgsChargeAttempt($attempt->id))->handle();

    expect($payment->refresh()->status)->toBe('completed');
    expect($attempt->refresh()->status)->toBe('approved');
});

it('marks a declined attempt declined and fails the pending payment', function () {
    [$payment, $attempt] = mpgsResolvable();

    Http::fake(['*/order/*' => Http::response(mpgsOrderBody([
        ['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED', 'acquirerMessage' => 'Do not honour'], 'transaction' => ['id' => 'txn-pay', 'type' => 'PAYMENT']],
    ], 'FAILED'), 200)]);

    (new ResolveMpgsChargeAttempt($attempt->id))->handle();

    expect($payment->refresh()->status)->toBe('failed');
    expect($attempt->refresh()->status)->toBe('declined');
    expect($attempt->decline_reason)->toBe("Card's bank declined (Do not honour)");
});

it('keeps the attempt pending and retries when the order has no matching PAY transaction', function () {
    [$payment, $attempt] = mpgsResolvable();

    Http::fake(['*/order/*' => Http::response(mpgsOrderBody([mpgsApprovedTransaction('some-other-txn')]), 200)]);

    expect(fn () => (new ResolveMpgsChargeAttempt($attempt->id))->handle())->toThrow(RuntimeException::class);

    expect($attempt->refresh()->status)->toBe('pending');
    expect($attempt->attempts_count)->toBe(1);
    expect($attempt->last_checked_at)->not->toBeNull();
    expect($payment->refresh()->status)->toBe('pending');
});

it('retries when the gateway has no such order yet (404)', function () {
    [$payment, $attempt] = mpgsResolvable();

    Http::fake(['*/order/*' => Http::response(['result' => 'ERROR', 'error' => ['cause' => 'INVALID_REQUEST']], 404)]);

    expect(fn () => (new ResolveMpgsChargeAttempt($attempt->id))->handle())->toThrow(RuntimeException::class);

    expect($attempt->refresh()->status)->toBe('pending');
    expect($payment->refresh()->status)->toBe('pending');
});

it('never completes a payment when success has no captured order (stays unresolved)', function () {
    [$payment, $attempt] = mpgsResolvable();

    Http::fake(['*/order/*' => Http::response(mpgsOrderBody([mpgsApprovedTransaction()], 'AUTHORIZED'), 200)]);

    expect(fn () => (new ResolveMpgsChargeAttempt($attempt->id))->handle())->toThrow(RuntimeException::class);

    expect($payment->refresh()->status)->toBe('pending');
});

it('is a no-op when the attempt was already resolved', function () {
    Queue::fake([SendPaymentNotification::class]);
    Http::fake();
    $payment = Payment::factory()->mpgs()->create(['status' => 'completed']);
    $attempt = MpgsChargeAttempt::factory()->create(['payment_id' => $payment->id, 'status' => 'approved']);

    (new ResolveMpgsChargeAttempt($attempt->id))->handle();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('abandons the attempt and clears the session when retries are exhausted, leaving the payment alone', function () {
    [$payment, $attempt] = mpgsResolvable();

    (new ResolveMpgsChargeAttempt($attempt->id))->failed(new RuntimeException('gone'));

    $attempt->refresh();
    expect($attempt->status)->toBe('abandoned');
    expect($attempt->session_id)->toBeNull();
    expect($payment->refresh()->status)->toBe('pending');
});

it('does not touch an attempt that already reached a final state when failed() runs', function () {
    $attempt = MpgsChargeAttempt::factory()->declined()->create();

    (new ResolveMpgsChargeAttempt($attempt->id))->failed(null);

    expect($attempt->refresh()->status)->toBe('declined');
});

it('retries with the spec backoff schedule', function () {
    $job = new ResolveMpgsChargeAttempt(1);

    expect($job->tries)->toBe(5);
    expect($job->backoff)->toBe([30, 60, 120, 300, 600]);
});

// AC-10: the sweep re-dispatches stale pending attempts and expires stale prepared ones, no gateway calls.
it('sweeps: re-dispatches only stale pending attempts', function () {
    Queue::fake();
    Http::fake();

    $stale = MpgsChargeAttempt::factory()->pending()->create(['updated_at' => now()->subMinutes(30), 'last_checked_at' => null]);
    $staleChecked = MpgsChargeAttempt::factory()->pending()->create(['updated_at' => now()->subMinutes(30), 'last_checked_at' => now()->subMinutes(15)]);
    $justChecked = MpgsChargeAttempt::factory()->pending()->create(['updated_at' => now()->subMinutes(30), 'last_checked_at' => now()->subMinutes(3)]);
    $inFlight = MpgsChargeAttempt::factory()->pending()->create(['last_checked_at' => null]); // PAY sent seconds ago
    $approved = MpgsChargeAttempt::factory()->create(['updated_at' => now()->subMinutes(30)]);

    (new MpgsStaleAttemptSweep)();

    Queue::assertPushed(ResolveMpgsChargeAttempt::class, 2);
    Queue::assertPushed(ResolveMpgsChargeAttempt::class, fn ($job) => $job->attemptId === $stale->id);
    Queue::assertPushed(ResolveMpgsChargeAttempt::class, fn ($job) => $job->attemptId === $staleChecked->id);
    Queue::assertNotPushed(ResolveMpgsChargeAttempt::class, fn ($job) => in_array($job->attemptId, [$justChecked->id, $inFlight->id, $approved->id], true));
    Http::assertNothingSent();
});

it('sweeps: expires prepared attempts past the session lifetime without calling the gateway', function () {
    Queue::fake();
    Http::fake();
    config(['services.mpgs.session_lifetime_minutes' => 30]);

    $expired = MpgsChargeAttempt::factory()->prepared()->create(['created_at' => now()->subMinutes(45)]);
    $fresh = MpgsChargeAttempt::factory()->prepared()->create(['created_at' => now()->subMinutes(5)]);

    (new MpgsStaleAttemptSweep)();

    expect($expired->refresh()->status)->toBe('abandoned');
    expect($expired->session_id)->toBeNull();
    expect($expired->payment->fresh()->status)->toBe('pending'); // payment untouched
    expect($fresh->refresh()->status)->toBe('prepared');
    expect($fresh->session_id)->not->toBeNull();
    Http::assertNothingSent();
});

it('schedules the sweep every 10 minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => $e->description === 'mpgs:sweep-stale-attempts');

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('*/10 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});
