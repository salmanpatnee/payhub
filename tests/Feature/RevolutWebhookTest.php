<?php

use App\Jobs\HandleRevolutWebhookJob;
use App\Models\Payment;
use App\Models\ProcessedRevolutEvent;
use App\Models\RevolutAccount;
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
 * Build a valid Revolut signature for the payload: HMAC-SHA256 over
 * "v1.{timestamp}.{payload}" with the signing secret, prefixed "v1=".
 *
 * @return array{ts: string, sig: string}
 */
function revolutSignature(string $payload, string $secret, ?int $timestampMs = null): array
{
    $ts = (string) ($timestampMs ?? (int) round(microtime(true) * 1000));
    $sig = 'v1='.hash_hmac('sha256', 'v1.'.$ts.'.'.$payload, $secret);

    return ['ts' => $ts, 'sig' => $sig];
}

/**
 * Send a raw POST with the Revolut signature + timestamp headers, preserving
 * the exact body so the controller's HMAC check sees the same bytes.
 */
function revolutPost(string $url, string $payload, ?string $sig = null, ?string $ts = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sig !== null) {
        $server['HTTP_REVOLUT_SIGNATURE'] = $sig;
    }
    if ($ts !== null) {
        $server['HTTP_REVOLUT_REQUEST_TIMESTAMP'] = $ts;
    }

    return test()->call('POST', $url, [], [], [], $server, $payload);
}

function revolutPayload(string $event, string $orderId): string
{
    return json_encode(['event' => $event, 'order_id' => $orderId, 'merchant_order_ext_ref' => '#000123']);
}

it('GET /webhook/revolut/{id} returns 405 method not allowed', function () {
    $account = RevolutAccount::factory()->create();

    $this->get("/webhook/revolut/{$account->id}")->assertStatus(405);
});

it('POST to unknown account id returns 404', function () {
    $this->post('/webhook/revolut/99999')->assertStatus(404);
});

it('returns 400 for missing signature headers', function () {
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payload = revolutPayload('ORDER_COMPLETED', 'ord_test');

    revolutPost("/webhook/revolut/{$account->id}", $payload)->assertStatus(400);
});

it('returns 400 for a tampered signature', function () {
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payload = revolutPayload('ORDER_COMPLETED', 'ord_test');

    revolutPost("/webhook/revolut/{$account->id}", $payload, 'v1=deadbeef', (string) (int) round(microtime(true) * 1000))
        ->assertStatus(400);
});

it('returns 400 for a stale timestamp', function () {
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payload = revolutPayload('ORDER_COMPLETED', 'ord_test');
    // 10 minutes ago — beyond the 5-minute tolerance.
    $staleMs = (int) round(microtime(true) * 1000) - 600_000;
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123', $staleMs);

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(400);
});

it('valid signature with a handled event returns 200', function () {
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payload = revolutPayload('ORDER_COMPLETED', 'ord_test_abc');
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123');

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);
});

it('ORDER_COMPLETED dispatches the job and returns 200', function () {
    Queue::fake();

    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    Payment::factory()->revolut()->create([
        'status' => 'pending',
        'revolut_order_id' => 'ord_test_abc',
        'revolut_account_id' => $account->id,
    ]);

    $payload = revolutPayload('ORDER_COMPLETED', 'ord_test_abc');
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123');

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);

    Queue::assertPushed(HandleRevolutWebhookJob::class);
});

it('ORDER_COMPLETED sets status to completed and paid_at', function () {
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payment = Payment::factory()->revolut()->create([
        'status' => 'pending',
        'revolut_order_id' => 'ord_xyz',
        'revolut_account_id' => $account->id,
    ]);

    $payload = revolutPayload('ORDER_COMPLETED', 'ord_xyz');
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123');

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
});

it('ORDER_PAYMENT_FAILED sets status to failed without paid_at', function () {
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payment = Payment::factory()->revolut()->create([
        'status' => 'pending',
        'revolut_order_id' => 'ord_fail',
        'revolut_account_id' => $account->id,
    ]);

    $payload = revolutPayload('ORDER_PAYMENT_FAILED', 'ord_fail');
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123');

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe('failed');
    expect($payment->paid_at)->toBeNull();
});

it('is idempotent — a replayed ORDER_COMPLETED is a no-op', function () {
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payment = Payment::factory()->revolut()->create([
        'status' => 'pending',
        'revolut_order_id' => 'ord_dup',
        'revolut_account_id' => $account->id,
    ]);

    $payload = revolutPayload('ORDER_COMPLETED', 'ord_dup');
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123');

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);
    $firstPaidAt = $payment->refresh()->paid_at;

    // Replay the identical event — already recorded, so it must not re-process.
    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);

    expect($payment->refresh()->paid_at->equalTo($firstPaidAt))->toBeTrue();
    expect(ProcessedRevolutEvent::count())->toBe(1);
});

it('allows a failed payment to transition to completed on retry', function () {
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payment = Payment::factory()->revolut()->create([
        'status' => 'failed',
        'revolut_order_id' => 'ord_retry',
        'revolut_account_id' => $account->id,
    ]);

    $payload = revolutPayload('ORDER_COMPLETED', 'ord_retry');
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123');

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);

    expect($payment->refresh()->status)->toBe('completed');
});

it('ignores webhooks for an inactive account', function () {
    Queue::fake();

    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123', 'is_active' => false]);
    $payload = revolutPayload('ORDER_COMPLETED', 'ord_inactive');
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123');

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);

    Queue::assertNothingPushed();
});

it('acknowledges but does not dispatch for unhandled events', function () {
    Queue::fake();

    $account = RevolutAccount::factory()->create(['webhook_secret' => 'wsk_test123']);
    $payload = revolutPayload('ORDER_AUTHORISED', 'ord_auth');
    ['ts' => $ts, 'sig' => $sig] = revolutSignature($payload, 'wsk_test123');

    revolutPost("/webhook/revolut/{$account->id}", $payload, $sig, $ts)->assertStatus(200);

    Queue::assertNothingPushed();
});
