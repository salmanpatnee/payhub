<?php

use App\Jobs\HandleVivaWebhookJob;
use App\Models\Payment;
use App\Models\VivaAccount;
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
 * Viva signature = hex( HMAC-SHA256( rawBody, webhook_verification_key ) ).
 */
function vivaSignature(string $payload, string $key): string
{
    return hash_hmac('sha256', $payload, $key);
}

function vivaPost(string $url, string $payload, ?string $sig = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sig !== null) {
        $server['HTTP_VIVA_SIGNATURE'] = $sig;
    }

    return test()->call('POST', $url, [], [], [], $server, $payload);
}

function vivaEventPayload(string $messageId, string $orderCode, ?string $transactionId, int $eventTypeId = 1796): string
{
    return json_encode([
        'MessageId' => $messageId,
        'EventTypeId' => $eventTypeId,
        'EventData' => [
            'OrderCode' => $orderCode,
            'TransactionId' => $transactionId,
        ],
    ]);
}

it('GET /webhook/viva/{id} returns the verification key handshake', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_verify_key_123';
    $account->save();

    $this->get("/webhook/viva/{$account->id}")
        ->assertOk()
        ->assertJson(['Key' => 'viva_verify_key_123']);
});

it('POST to unknown viva account id returns 404', function () {
    $this->post('/webhook/viva/99999')->assertStatus(404);
});

it('returns 400 for missing signature header', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payload = vivaEventPayload('evt_1', 'order_1', 'txn_1');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(400);
});

it('returns 400 for tampered signature', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payload = vivaEventPayload('evt_2', 'order_2', 'txn_2');

    vivaPost("/webhook/viva/{$account->id}", $payload, 'invalidsignature')->assertStatus(400);
});

it('valid signature with TransactionPaymentCreated returns 200', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payload = vivaEventPayload('evt_valid', 'order_valid', 'txn_valid');
    $sig = vivaSignature($payload, 'viva_sig_key_123');

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);
});

it('TransactionPaymentCreated dispatches HandleVivaWebhookJob', function () {
    Queue::fake();

    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_dispatch',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_dispatch', 'order_dispatch', 'txn_dispatch');
    $sig = vivaSignature($payload, 'viva_sig_key_123');

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);

    Queue::assertPushed(HandleVivaWebhookJob::class);
});

it('TransactionPaymentCreated sets status to completed and paid_at', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_completed',
        'viva_account_id' => $account->id,
        'viva_transaction_id' => null,
        'paid_at' => null,
    ]);

    $payload = vivaEventPayload('evt_completed', 'order_completed', 'txn_completed');
    $sig = vivaSignature($payload, 'viva_sig_key_123');

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
    expect($payment->viva_transaction_id)->toBe('txn_completed');
});

it('unhandled event type id is acknowledged but not processed', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_unhandled',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_unhandled', 'order_unhandled', 'txn_unhandled', eventTypeId: 1798);
    $sig = vivaSignature($payload, 'viva_sig_key_123');

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});

it('replayed event id is idempotent (no-op)', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_replay',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_replay', 'order_replay', 'txn_replay');
    $sig = vivaSignature($payload, 'viva_sig_key_123');

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);
    $this->assertDatabaseHas('processed_viva_events', ['event_key' => 'evt_replay']);

    // Replay the same event — completed payment must not change, no second processing.
    $payment->refresh();
    $completedAt = $payment->paid_at;

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at->toDateTimeString())->toBe($completedAt->toDateTimeString());
});

it('viva webhook route has no csrf protection', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payload = vivaEventPayload('evt_csrf', 'order_csrf', 'txn_csrf');
    $sig = vivaSignature($payload, 'viva_sig_key_123');

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);
});

it('TransactionPaymentCreated on a failed payment completes it (retry transition)', function () {
    $account = VivaAccount::factory()->create();
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payment = Payment::factory()->viva()->create([
        'status' => 'failed',
        'viva_order_code' => 'order_retry',
        'viva_account_id' => $account->id,
        'paid_at' => null,
    ]);

    $payload = vivaEventPayload('evt_retry', 'order_retry', 'txn_retry');
    $sig = vivaSignature($payload, 'viva_sig_key_123');

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
});

it('inactive viva account acknowledges with 200 and does not process', function () {
    $account = VivaAccount::factory()->create(['is_active' => false]);
    $account->webhook_verification_key = 'viva_sig_key_123';
    $account->save();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_inactive',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_inactive', 'order_inactive', 'txn_inactive');
    $sig = vivaSignature($payload, 'viva_sig_key_123');

    vivaPost("/webhook/viva/{$account->id}", $payload, $sig)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});
