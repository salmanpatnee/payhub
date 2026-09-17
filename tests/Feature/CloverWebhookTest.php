<?php

use App\Jobs\HandleCloverWebhookJob;
use App\Models\CloverAccount;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

/**
 * Clover-Signature header = "t={timestamp},v1=" + HMAC-SHA256("{timestamp}.{rawBody}", secret).
 */
function cloverSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= now()->timestamp;
    $hmac = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$hmac}";
}

function cloverPost(string $url, string $payload, ?string $sig = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sig !== null) {
        $server['HTTP_CLOVER_SIGNATURE'] = $sig;
    }

    return test()->call('POST', $url, [], [], [], $server, $payload);
}

function cloverPaymentPayload(string $status, string $paymentId, string $checkoutSessionId): string
{
    return json_encode([
        'Type' => 'PAYMENT',
        'Status' => $status,
        'Id' => $paymentId,
        'Data' => $checkoutSessionId,
    ]);
}

it('GET /webhook/clover/{id} returns 405 method not allowed', function () {
    $account = CloverAccount::factory()->create();

    $this->get("/webhook/clover/{$account->id}")->assertStatus(405);
});

it('POST to unknown clover account id returns 404', function () {
    $this->post('/webhook/clover/99999')->assertStatus(404);
});

it('returns 401 for missing signature header', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_1', 'sess_1');

    cloverPost("/webhook/clover/{$account->id}", $payload)->assertStatus(401);
});

it('returns 401 for tampered signature', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_2', 'sess_2');

    cloverPost("/webhook/clover/{$account->id}", $payload, 't=123,v1=invalidsignature')->assertStatus(401);
});

it('valid signature with APPROVED payment returns 200', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    Payment::factory()->clover()->create([
        'status' => 'pending',
        'clover_account_id' => $account->id,
        'clover_checkout_session_id' => 'sess_valid',
    ]);

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_valid', 'sess_valid');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);
});

it('APPROVED dispatches HandleCloverWebhookJob', function () {
    Queue::fake();

    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    Payment::factory()->clover()->create([
        'status' => 'pending',
        'clover_account_id' => $account->id,
        'clover_checkout_session_id' => 'sess_dispatch',
    ]);

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_dispatch', 'sess_dispatch');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);

    Queue::assertPushed(HandleCloverWebhookJob::class);
});

it('APPROVED sets status to completed, paid_at, and clover_payment_id', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payment = Payment::factory()->clover()->create([
        'status' => 'pending',
        'clover_account_id' => $account->id,
        'clover_checkout_session_id' => 'sess_completed',
        'paid_at' => null,
    ]);

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_completed', 'sess_completed');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
    expect($payment->clover_payment_id)->toBe('cl_pay_completed');
});

it('DECLINED sets status to failed and records clover_payment_id', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payment = Payment::factory()->clover()->create([
        'status' => 'pending',
        'clover_account_id' => $account->id,
        'clover_checkout_session_id' => 'sess_declined',
        'paid_at' => null,
    ]);

    $payload = cloverPaymentPayload('DECLINED', 'cl_pay_declined', 'sess_declined');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('failed');
    expect($payment->paid_at)->toBeNull();
    expect($payment->clover_payment_id)->toBe('cl_pay_declined');
});

it('replayed event is idempotent (no-op)', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payment = Payment::factory()->clover()->create([
        'status' => 'pending',
        'clover_account_id' => $account->id,
        'clover_checkout_session_id' => 'sess_replay',
    ]);

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_replay', 'sess_replay');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);
    $this->assertDatabaseHas('processed_clover_events', ['event_key' => "{$payment->id}:sess_replay"]);

    $payment->refresh();
    $paidAt = $payment->paid_at;

    // Replay the same event — completed payment must not change, no second processing.
    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at->toDateTimeString())->toBe($paidAt->toDateTimeString());
});

it('a delayed DECLINED for an earlier session never overwrites an already-completed payment', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payment = Payment::factory()->clover()->create([
        'status' => 'completed',
        'clover_account_id' => $account->id,
        'clover_checkout_session_id' => 'sess_terminal',
        'clover_payment_id' => 'cl_pay_original',
        'paid_at' => now(),
    ]);

    $payload = cloverPaymentPayload('DECLINED', 'cl_pay_late', 'sess_terminal');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->clover_payment_id)->toBe('cl_pay_original');
});

it('clover webhook route has no csrf protection', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_csrf', 'sess_csrf');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);
});

it('APPROVED on an already-failed payment is a no-op — unlike Square/Viva, failed is terminal for Clover', function () {
    $account = CloverAccount::factory()->create();
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payment = Payment::factory()->clover()->create([
        'status' => 'failed',
        'clover_account_id' => $account->id,
        'clover_checkout_session_id' => 'sess_retry',
        'clover_payment_id' => 'cl_pay_original_decline',
        'paid_at' => null,
    ]);

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_retry', 'sess_retry');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('failed');
    expect($payment->paid_at)->toBeNull();
    expect($payment->clover_payment_id)->toBe('cl_pay_original_decline');
});

it('inactive clover account acknowledges with 200 and does not process', function () {
    $account = CloverAccount::factory()->create(['is_active' => false]);
    $account->webhook_secret = 'clover_sig_key_123';
    $account->save();

    $payment = Payment::factory()->clover()->create([
        'status' => 'pending',
        'clover_account_id' => $account->id,
        'clover_checkout_session_id' => 'sess_inactive',
    ]);

    $payload = cloverPaymentPayload('APPROVED', 'cl_pay_inactive', 'sess_inactive');
    $sig = cloverSignatureHeader($payload, 'clover_sig_key_123');

    cloverPost("/webhook/clover/{$account->id}", $payload, $sig)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});
