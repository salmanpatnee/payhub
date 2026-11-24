<?php

use App\Jobs\ResolveCloverChargeAttempt;
use App\Jobs\SendPaymentNotification;
use App\Models\CloverChargeAttempt;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
    Http::preventStrayRequests();
    Queue::fake([SendPaymentNotification::class, ResolveCloverChargeAttempt::class]);
});

function clvCharge(Payment $payment, array $body, int $status = 200): void
{
    Http::fake([
        'scl-sandbox.dev.clover.com/v1/charges' => Http::response($body, $status),
        'scl-sandbox.dev.clover.com/v1/charges/*' => Http::response($body, $status),
    ]);

    test()->postJson("/pay/{$payment->uuid}/clover/charge", ['token' => 'clv_test_token']);
}

function clvPayment(): Payment
{
    return Payment::factory()->clover()->create(['status' => 'pending', 'amount' => 5000, 'currency' => 'usd']);
}

it('logs an approved charge as succeeded', function () {
    $payment = clvPayment();
    clvCharge($payment, ['id' => 'chg_1', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd']);

    $row = PaymentAttempt::where('payment_id', $payment->id)->sole();
    expect($row->provider->value ?? $row->provider)->toBe('clover')
        ->and($row->status)->toBe('succeeded')
        ->and($row->reference)->toBe('chg_1')
        ->and($row->amount)->toBe(5000)
        ->and($row->source)->toBe('call');
});

it('logs a decline with Clover decline code and message', function () {
    $payment = clvPayment();
    clvCharge($payment, ['error' => ['code' => 'card_declined', 'declineCode' => 'issuer_declined', 'charge' => 'chg_d', 'message' => 'The card was declined.']], 402);

    $row = PaymentAttempt::where('payment_id', $payment->id)->sole();
    expect($row->status)->toBe('failed')
        ->and($row->event)->toBe('declined')
        ->and($row->error_code)->toBe('issuer_declined')
        ->and($row->reason)->toBe('The card was declined.')
        ->and($row->reference)->toBe('chg_d')
        ->and($row->amount)->toBe(5000);
    expect($payment->refresh()->status)->toBe('failed');
});

it('logs a hard error as failed', function () {
    $payment = clvPayment();
    clvCharge($payment, ['error' => ['type' => 'invalid_request_error', 'message' => 'bad request']], 400);

    expect(PaymentAttempt::where('payment_id', $payment->id)->sole()->event)->toBe('hard_error');
});

it('does not log an unclear reply', function () {
    $payment = clvPayment();
    clvCharge($payment, [], 500);

    expect(PaymentAttempt::where('payment_id', $payment->id)->count())->toBe(0);
});

it('logs the resolver job outcome once, even when the attempt is resolved twice', function () {
    $payment = clvPayment();
    $attempt = CloverChargeAttempt::factory()->pending()->create(['payment_id' => $payment->id, 'clover_charge_id' => 'chg_k']);
    Http::fake(['scl-sandbox.dev.clover.com/v1/charges/chg_k' => Http::response([
        'id' => 'chg_k', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd',
    ], 200)]);

    (new ResolveCloverChargeAttempt($attempt->id))->handle();
    $attempt->update(['status' => 'pending']);
    (new ResolveCloverChargeAttempt($attempt->id))->handle();

    expect(PaymentAttempt::where('payment_id', $payment->id)->count())->toBe(1);
});

it('still completes the payment and answers the same when the log table is broken', function () {
    $payment = clvPayment();
    Schema::drop('payment_attempts');

    clvCharge($payment, ['id' => 'chg_b', 'paid' => true, 'captured' => true, 'amount' => 5000, 'currency' => 'usd']);

    expect($payment->refresh()->status)->toBe('completed');
});

it('stores no card number or token from the reply and shows the log to admins only', function () {
    $payment = clvPayment();
    clvCharge($payment, ['error' => ['code' => 'card_declined', 'declineCode' => 'issuer_declined', 'message' => 'Card 4242424242424242 declined', 'token' => 'clv_secret']], 402);

    $row = PaymentAttempt::where('payment_id', $payment->id)->sole();
    expect(json_encode($row->getAttributes()))->not->toContain('4242424242424242')->not->toContain('clv_secret');

    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create()->assignRole('agent');
    $payment->update(['user_id' => $agent->id]);

    Http::fake(['*/__inertia_ssr' => Http::response('', 404)]);
    $props = fn (User $u) => test()->actingAs($u)->get(route('payments.show', $payment))->assertOk()->viewData('page')['props'] ?? null;
    expect($props($admin)['attemptLog']['provider'])->toBe('clover');
    expect($props($agent)['attemptLog'])->toBeNull();
});
