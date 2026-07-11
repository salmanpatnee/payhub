<?php

use App\Jobs\HandleVivaWebhookJob;
use App\Models\Payment;
use App\Models\VivaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
});

/**
 * Viva payment webhooks are NOT signed — the endpoint accepts the raw POST and
 * defers all trust to HandleVivaWebhookJob, which re-fetches the transaction
 * from Viva's API. These helpers fake that API (demo hosts, per the factory's
 * default 'demo' environment): the OAuth token call + the transaction lookup.
 */
function fakeVivaApi(?string $orderCode = 'order_completed', string $statusId = 'F'): void
{
    Http::fake([
        '*demo-accounts.vivapayments.com/connect/token' => Http::response([
            'access_token' => 'test_access_token',
            'expires_in' => 3600,
        ]),
        '*demo-api.vivapayments.com/checkout/v2/transactions/*' => Http::response(array_filter([
            'statusId' => $statusId,
            'orderCode' => $orderCode,
        ], fn ($v): bool => $v !== null)),
    ]);
}

function vivaPost(string $url, string $payload): TestResponse
{
    return test()->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
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

it('TransactionPaymentCreated returns 200 and dispatches HandleVivaWebhookJob', function () {
    Queue::fake();

    $account = VivaAccount::factory()->create();

    Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_dispatch',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_dispatch', 'order_dispatch', 'txn_dispatch');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    Queue::assertPushed(HandleVivaWebhookJob::class);
});

it('completes the payment when Viva confirms the transaction succeeded', function () {
    fakeVivaApi(orderCode: 'order_completed', statusId: 'F');

    $account = VivaAccount::factory()->create();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_completed',
        'viva_account_id' => $account->id,
        'viva_transaction_id' => null,
        'paid_at' => null,
    ]);

    $payload = vivaEventPayload('evt_completed', 'order_completed', 'txn_completed');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
    expect($payment->viva_transaction_id)->toBe('txn_completed');
});

it('does NOT complete the payment when Viva reports the transaction did not succeed', function () {
    fakeVivaApi(orderCode: 'order_unpaid', statusId: 'E'); // E = error/refused

    $account = VivaAccount::factory()->create();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_unpaid',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_unpaid', 'order_unpaid', 'txn_unpaid');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});

it('does NOT complete when the webhook carries no TransactionId (unverifiable)', function () {
    fakeVivaApi(orderCode: 'order_notxn', statusId: 'F');

    $account = VivaAccount::factory()->create();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_notxn',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_notxn', 'order_notxn', null);

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});

it('does NOT complete when the retrieved transaction belongs to a different order', function () {
    fakeVivaApi(orderCode: 'some_other_order', statusId: 'F');

    $account = VivaAccount::factory()->create();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_mismatch',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_mismatch', 'order_mismatch', 'txn_mismatch');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});

it('unhandled event type id is acknowledged but not processed', function () {
    $account = VivaAccount::factory()->create();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_unhandled',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_unhandled', 'order_unhandled', 'txn_unhandled', eventTypeId: 1798);

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});

it('replayed event id is idempotent (no-op)', function () {
    fakeVivaApi(orderCode: 'order_replay', statusId: 'F');

    $account = VivaAccount::factory()->create();

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_replay',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_replay', 'order_replay', 'txn_replay');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);
    $this->assertDatabaseHas('processed_viva_events', ['event_key' => 'evt_replay']);

    // Replay the same event — completed payment must not change, no second processing.
    $payment->refresh();
    $completedAt = $payment->paid_at;

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at->toDateTimeString())->toBe($completedAt->toDateTimeString());
});

it('viva webhook route has no csrf protection', function () {
    Queue::fake();

    $account = VivaAccount::factory()->create();

    $payload = vivaEventPayload('evt_csrf', 'order_csrf', 'txn_csrf');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);
});

it('TransactionPaymentCreated on a failed payment completes it (retry transition)', function () {
    fakeVivaApi(orderCode: 'order_retry', statusId: 'F');

    $account = VivaAccount::factory()->create();

    $payment = Payment::factory()->viva()->create([
        'status' => 'failed',
        'viva_order_code' => 'order_retry',
        'viva_account_id' => $account->id,
        'paid_at' => null,
    ]);

    $payload = vivaEventPayload('evt_retry', 'order_retry', 'txn_retry');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
});

it('inactive viva account acknowledges with 200 and does not process', function () {
    $account = VivaAccount::factory()->create(['is_active' => false]);

    $payment = Payment::factory()->viva()->create([
        'status' => 'pending',
        'viva_order_code' => 'order_inactive',
        'viva_account_id' => $account->id,
    ]);

    $payload = vivaEventPayload('evt_inactive', 'order_inactive', 'txn_inactive');

    vivaPost("/webhook/viva/{$account->id}", $payload)->assertStatus(200);

    expect($payment->fresh()->status)->toBe('pending');
});
