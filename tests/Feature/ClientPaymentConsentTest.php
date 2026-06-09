<?php

use App\Models\Payment;
use App\Models\PaymentConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Records a consent row with the configured policy versions for a pending payment.
it('records consent for a pending payment', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);

    $this->post("/pay/{$payment->uuid}/consent", ['accepted' => true])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $consent = $payment->consents()->sole();

    expect($consent->policy_versions)->toBe([
        'terms' => config('policies.terms.version'),
        'refund' => config('policies.refund.version'),
        'privacy' => config('policies.privacy.version'),
    ]);
    expect($consent->accepted_at)->not->toBeNull();
    expect($consent->ip_address)->not->toBeNull();
});

// failed payments are payable (retry) — consent must still be recordable.
it('records consent for a failed payment', function () {
    $payment = Payment::factory()->create(['status' => 'failed']);

    $this->post("/pay/{$payment->uuid}/consent", ['accepted' => true])->assertOk();

    expect($payment->consents()->count())->toBe(1);
});

// Unchecked / falsy acceptance fails validation and writes nothing.
it('rejects consent when accepted is false', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);

    $this->postJson("/pay/{$payment->uuid}/consent", ['accepted' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('accepted');

    expect(PaymentConsent::count())->toBe(0);
});

it('rejects consent when accepted is missing', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);

    $this->postJson("/pay/{$payment->uuid}/consent", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('accepted');

    expect(PaymentConsent::count())->toBe(0);
});

// Non-payable statuses cannot record consent (same guard as show()).
it('rejects consent for a completed payment', function () {
    $payment = Payment::factory()->create(['status' => 'completed']);

    $this->post("/pay/{$payment->uuid}/consent", ['accepted' => true])
        ->assertStatus(422);

    expect(PaymentConsent::count())->toBe(0);
});

it('rejects consent for a cancelled payment', function () {
    $payment = Payment::factory()->create(['status' => 'cancelled']);

    $this->post("/pay/{$payment->uuid}/consent", ['accepted' => true])
        ->assertStatus(422);

    expect(PaymentConsent::count())->toBe(0);
});

// Unknown payment UUID returns 404.
it('returns 404 for an unknown payment', function () {
    $this->post('/pay/00000000-0000-0000-0000-000000000000/consent', ['accepted' => true])
        ->assertStatus(404);
});

// The consents() relation resolves the created record.
it('exposes consents via the Payment relation', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);

    $this->post("/pay/{$payment->uuid}/consent", ['accepted' => true])->assertOk();

    expect($payment->fresh()->consents->first())->toBeInstanceOf(PaymentConsent::class);
});
