<?php

use App\Jobs\HandleSquareWebhookJob;
use App\Models\Payment;
use App\Models\SquareAccount;
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
 * Square signature = base64( HMAC-SHA256( notificationUrl + requestBody, signatureKey ) ).
 */
function squareSignature(SquareAccount $account, string $payload, string $key): string
{
    $url = route('webhook.square', $account, true);

    return base64_encode(hash_hmac('sha256', $url.$payload, $key, true));
}

function squarePost(string $url, string $payload, ?string $sig = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sig !== null) {
        $server['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] = $sig;
    }

    return test()->call('POST', $url, [], [], [], $server, $payload);
}

function squarePaymentUpdatedPayload(string $eventId, string $squarePaymentId, string $status): string
{
    return json_encode([
        'event_id' => $eventId,
        'type' => 'payment.updated',
        'data' => [
            'type' => 'payment',
            'object' => [
                'payment' => ['id' => $squarePaymentId, 'status' => $status],
            ],
        ],
    ]);
}

it('GET /webhook/square/{id} returns 405 method not allowed', function () {
    $account = SquareAccount::factory()->create();

    $this->get("/webhook/square/{$account->id}")->assertStatus(405);
});

it('POST to unknown square account id returns 404', function () {
    $this->post('/webhook/square/99999')->assertStatus(404);
});

it('returns 403 for missing signature header', function () {
    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payload = squarePaymentUpdatedPayload('evt_1', 'sq_pay_1', 'COMPLETED');

    squarePost("/webhook/square/{$account->id}", $payload)->assertStatus(403);
});

it('returns 403 for tampered signature', function () {
    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payload = squarePaymentUpdatedPayload('evt_2', 'sq_pay_2', 'COMPLETED');

    squarePost("/webhook/square/{$account->id}", $payload, 'invalidsignature')->assertStatus(403);
});

it('valid signature with payment.updated returns 200', function () {
    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payload = squarePaymentUpdatedPayload('evt_valid', 'sq_pay_valid', 'COMPLETED');
    $sig = squareSignature($account, $payload, 'sq_sig_key_123');

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);
});

it('payment.updated dispatches HandleSquareWebhookJob', function () {
    Queue::fake();

    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    Payment::factory()->square()->create([
        'status' => 'pending',
        'square_payment_id' => 'sq_pay_dispatch',
        'square_account_id' => $account->id,
    ]);

    $payload = squarePaymentUpdatedPayload('evt_dispatch', 'sq_pay_dispatch', 'COMPLETED');
    $sig = squareSignature($account, $payload, 'sq_sig_key_123');

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);

    Queue::assertPushed(HandleSquareWebhookJob::class);
});

it('COMPLETED sets status to completed and paid_at', function () {
    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payment = Payment::factory()->square()->create([
        'status' => 'pending',
        'square_payment_id' => 'sq_pay_completed',
        'square_account_id' => $account->id,
        'paid_at' => null,
    ]);

    $payload = squarePaymentUpdatedPayload('evt_completed', 'sq_pay_completed', 'COMPLETED');
    $sig = squareSignature($account, $payload, 'sq_sig_key_123');

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
});

it('FAILED sets status to failed', function () {
    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payment = Payment::factory()->square()->create([
        'status' => 'pending',
        'square_payment_id' => 'sq_pay_failed',
        'square_account_id' => $account->id,
        'paid_at' => null,
    ]);

    $payload = squarePaymentUpdatedPayload('evt_failed', 'sq_pay_failed', 'FAILED');
    $sig = squareSignature($account, $payload, 'sq_sig_key_123');

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('failed');
    expect($payment->paid_at)->toBeNull();
});

it('replayed event id is idempotent (no-op)', function () {
    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payment = Payment::factory()->square()->create([
        'status' => 'pending',
        'square_payment_id' => 'sq_pay_replay',
        'square_account_id' => $account->id,
    ]);

    $payload = squarePaymentUpdatedPayload('evt_replay', 'sq_pay_replay', 'COMPLETED');
    $sig = squareSignature($account, $payload, 'sq_sig_key_123');

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);
    $this->assertDatabaseHas('processed_square_events', ['square_event_id' => 'evt_replay']);

    // Replay the same event — completed payment must not change, no second processing.
    $payment->refresh();
    $completedAt = $payment->paid_at;

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at->toDateTimeString())->toBe($completedAt->toDateTimeString());
});

it('square webhook route has no csrf protection', function () {
    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payload = squarePaymentUpdatedPayload('evt_csrf', 'sq_pay_csrf', 'COMPLETED');
    $sig = squareSignature($account, $payload, 'sq_sig_key_123');

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);
});

it('COMPLETED on a failed payment completes it (retry transition)', function () {
    $account = SquareAccount::factory()->create();
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payment = Payment::factory()->square()->create([
        'status' => 'failed',
        'square_payment_id' => 'sq_pay_retry',
        'square_account_id' => $account->id,
        'paid_at' => null,
    ]);

    $payload = squarePaymentUpdatedPayload('evt_retry', 'sq_pay_retry', 'COMPLETED');
    $sig = squareSignature($account, $payload, 'sq_sig_key_123');

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
});

it('inactive square account acknowledges with 200 and does not process', function () {
    $account = SquareAccount::factory()->create(['is_active' => false]);
    $account->webhook_signature_key = 'sq_sig_key_123';
    $account->save();

    $payment = Payment::factory()->square()->create([
        'status' => 'pending',
        'square_payment_id' => 'sq_pay_inactive',
        'square_account_id' => $account->id,
    ]);

    $payload = squarePaymentUpdatedPayload('evt_inactive', 'sq_pay_inactive', 'COMPLETED');
    $sig = squareSignature($account, $payload, 'sq_sig_key_123');

    squarePost("/webhook/square/{$account->id}", $payload, $sig)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});
