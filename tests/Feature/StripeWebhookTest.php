<?php

use App\Jobs\HandleStripeWebhookJob;
use App\Models\Payment;
use App\Models\StripeAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

function fakeStripeSignature(string $payload, string $secret): string
{
    $timestamp = time();
    $signedPayload = $timestamp.'.'.$payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}

/**
 * Send a raw POST request with a Stripe-Signature header.
 * Uses call() with HTTP_STRIPE_SIGNATURE in the server vars so the
 * raw body is preserved exactly for Webhook::constructEvent().
 */
function stripePost(string $url, string $payload, ?string $sig = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sig !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $sig;
    }

    return test()->call('POST', $url, [], [], [], $server, $payload);
}

// WEBHOOK-01: only POST is accepted
it('GET /webhook/stripe/{id} returns 405 method not allowed', function () {
    $account = StripeAccount::factory()->create();

    $this->get("/webhook/stripe/{$account->id}")
        ->assertStatus(405);
});

// WEBHOOK-01: unknown account id
it('POST to unknown account id returns 404', function () {
    $this->post('/webhook/stripe/99999')
        ->assertStatus(404);
});

// WEBHOOK-02: missing Stripe-Signature header
it('returns 400 for missing Stripe-Signature header', function () {
    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    $payload = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test']]]);

    stripePost("/webhook/stripe/{$account->id}", $payload)
        ->assertStatus(400);
});

// WEBHOOK-02: tampered/invalid signature
it('returns 400 for tampered signature', function () {
    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    $payload = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test']]]);

    stripePost("/webhook/stripe/{$account->id}", $payload, 't=1234567890,v1=invalidsignature')
        ->assertStatus(400);
});

// WEBHOOK-02: valid signature returns 200
it('valid signature with handled event returns 200', function () {
    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    $payload = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_test_abc']],
    ]);
    $sig = fakeStripeSignature($payload, 'whsec_test123');

    stripePost("/webhook/stripe/{$account->id}", $payload, $sig)
        ->assertStatus(200);
});

// WEBHOOK-06: dispatches HandleStripeWebhookJob on payment_intent.succeeded
it('payment_intent.succeeded dispatches job and returns 200', function () {
    Queue::fake();

    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    Payment::factory()->create([
        'status' => 'pending',
        'stripe_payment_intent_id' => 'pi_test_abc',
        'stripe_account_id' => $account->id,
    ]);

    $payload = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_test_abc']],
    ]);
    $sig = fakeStripeSignature($payload, 'whsec_test123');

    stripePost("/webhook/stripe/{$account->id}", $payload, $sig)
        ->assertStatus(200);

    Queue::assertPushed(HandleStripeWebhookJob::class);
});

// WEBHOOK-03: payment_intent.succeeded sets status to completed and paid_at
it('payment_intent.succeeded sets status to completed and paid_at', function () {
    $this->markTestIncomplete('stub — Wave 2 (06-02): HandleStripeWebhookJob not yet implemented');
});

// WEBHOOK-04: payment_intent.payment_failed sets status to failed
it('payment_intent.payment_failed sets status to failed', function () {
    $this->markTestIncomplete('stub — Wave 2 (06-02): HandleStripeWebhookJob not yet implemented');
});

// WEBHOOK-05: idempotency — already completed payment is not re-processed
it('already completed payment is not re-processed', function () {
    $this->markTestIncomplete('stub — Wave 2 (06-02): HandleStripeWebhookJob not yet implemented');
});

// SEC-03: webhook route has no CSRF protection
it('webhook route has no csrf protection', function () {
    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    $payload = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_test_abc']],
    ]);
    $sig = fakeStripeSignature($payload, 'whsec_test123');

    // POST without a session/CSRF token — should not return 419
    stripePost("/webhook/stripe/{$account->id}", $payload, $sig)
        ->assertStatus(200);
});

// D-04: blank webhook_secret on submit preserves existing secret
it('blank webhook_secret on stripe account update preserves existing secret', function () {
    $this->markTestIncomplete('stub — Wave 3 (06-03): StripeAccountController webhook_secret update not yet implemented');
});
