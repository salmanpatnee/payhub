<?php

use App\Jobs\ResolveCloverChargeAttempt;
use App\Jobs\SendPaymentNotification;
use App\Models\CloverChargeAttempt;
use App\Models\Payment;
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

// AC-8: the resolver job reads Clover's own record of the charge by id once
// known — never re-submits anything.
it('resolves a pending attempt by reading the known charge id', function () {
    Queue::fake([SendPaymentNotification::class]);
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 1500, 'currency' => 'usd']);
    $attempt = CloverChargeAttempt::factory()->pending()->create([
        'payment_id' => $payment->id,
        'clover_charge_id' => 'chg_known',
    ]);

    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges/chg_known' => Http::response([
            'id' => 'chg_known', 'paid' => true, 'captured' => true, 'amount' => 1500, 'currency' => 'usd',
        ], 200),
    ]);

    (new ResolveCloverChargeAttempt($attempt->id))->handle();

    expect($payment->refresh()->status)->toBe('completed');
    expect($attempt->refresh()->status)->toBe('approved');
    Queue::assertPushed(SendPaymentNotification::class);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/charges/chg_known') && $request->method() === 'GET');
});

// AC-8: with no charge id ever captured, the job falls back to a read-only
// search by the attempt's idempotency_key/external_reference_id.
it('resolves a pending attempt with no known charge id via a reference search', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 800, 'currency' => 'usd']);
    $attempt = CloverChargeAttempt::factory()->pending()->create([
        'payment_id' => $payment->id,
        'idempotency_key' => 'idem_search_key',
    ]);

    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges*' => Http::response([
            'data' => [
                ['id' => 'chg_found', 'paid' => false, 'decline_code' => 'card_declined', 'external_reference_id' => 'idem_search_key'],
            ],
        ], 200),
    ]);

    (new ResolveCloverChargeAttempt($attempt->id))->handle();

    expect($payment->refresh()->status)->toBe('failed');
    expect($attempt->refresh()->status)->toBe('declined');
});

// AC-6, AC-7: idempotent double resolution — the job running after the
// synchronous handler already completed the payment must not double-dispatch
// the notification or change anything.
it('is a no-op when the attempt was already resolved', function () {
    Queue::fake([SendPaymentNotification::class]);
    $payment = Payment::factory()->clover()->create(['status' => 'completed']);
    $attempt = CloverChargeAttempt::factory()->create([
        'payment_id' => $payment->id,
        'status' => 'approved',
    ]);

    (new ResolveCloverChargeAttempt($attempt->id))->handle();

    Http::assertNothingSent();
    Queue::assertNotPushed(SendPaymentNotification::class);
});

// AC-8: once retries are exhausted without a resolution, the attempt is marked
// abandoned and the payment stays pending — never retried forever.
it('marks the attempt abandoned once retries are exhausted, leaving the payment pending', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    $attempt = CloverChargeAttempt::factory()->pending()->create(['payment_id' => $payment->id]);

    $job = new ResolveCloverChargeAttempt($attempt->id);
    $job->failed(new RuntimeException('still unresolved'));

    expect($attempt->refresh()->status)->toBe('abandoned');
    expect($payment->refresh()->status)->toBe('pending');
});

// failed() must never flip an attempt that resolved between the last retry and
// the final failure callback.
it('does not abandon an attempt that resolved before the final retry ran out', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'completed']);
    $attempt = CloverChargeAttempt::factory()->create(['payment_id' => $payment->id, 'status' => 'approved']);

    $job = new ResolveCloverChargeAttempt($attempt->id);
    $job->failed(new RuntimeException('stale failure callback'));

    expect($attempt->refresh()->status)->toBe('approved');
});
