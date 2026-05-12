<?php

use App\Jobs\HandleStripeWebhookJob;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
});

function fakeStripeSignature(string $payload, string $secret): string
{
    $timestamp = time();
    $signedPayload = $timestamp.'.'.$payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}

// WEBHOOK-01: only POST is accepted
it('GET /webhook/stripe/{id} returns 405 method not allowed', function () {
    $this->markTestIncomplete('stub');
});

// WEBHOOK-01: unknown account id
it('POST to unknown account id returns 404', function () {
    $this->markTestIncomplete('stub');
});

// WEBHOOK-02: missing Stripe-Signature header
it('returns 400 for missing Stripe-Signature header', function () {
    $this->markTestIncomplete('stub');
});

// WEBHOOK-02: tampered/invalid signature
it('returns 400 for tampered signature', function () {
    $this->markTestIncomplete('stub');
});

// WEBHOOK-02: valid signature returns 200
it('valid signature with handled event returns 200', function () {
    $this->markTestIncomplete('stub');
});

// WEBHOOK-06: dispatches HandleStripeWebhookJob on payment_intent.succeeded
it('payment_intent.succeeded dispatches job and returns 200', function () {
    $this->markTestIncomplete('stub');
});

// WEBHOOK-03: payment_intent.succeeded sets status to completed and paid_at
it('payment_intent.succeeded sets status to completed and paid_at', function () {
    $this->markTestIncomplete('stub');
});

// WEBHOOK-04: payment_intent.payment_failed sets status to failed
it('payment_intent.payment_failed sets status to failed', function () {
    $this->markTestIncomplete('stub');
});

// WEBHOOK-05: idempotency — already completed payment is not re-processed
it('already completed payment is not re-processed', function () {
    $this->markTestIncomplete('stub');
});

// SEC-03: webhook route has no CSRF protection
it('webhook route has no csrf protection', function () {
    $this->markTestIncomplete('stub');
});

// D-04: blank webhook_secret on submit preserves existing secret
it('blank webhook_secret on stripe account update preserves existing secret', function () {
    $this->markTestIncomplete('stub');
});
