<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
});

function mockStripeClient(): void
{
    $mockPi = PaymentIntent::constructFrom([
        'id'            => 'pi_test_mock123',
        'client_secret' => 'pi_test_mock123_secret_xyz',
    ]);

    $mockPaymentIntents = Mockery::mock();
    $mockPaymentIntents->shouldReceive('create')->andReturn($mockPi);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->paymentIntents = $mockPaymentIntents;

    app()->bind(StripeClient::class, fn () => $mockStripe);
}

// CLIENT-01: Guest can access pay route without authentication
it('guest can access pay route without authentication', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    mockStripeClient();
    $this->get("/pay/{$payment->uuid}")->assertStatus(200);
});

// CLIENT-01: Unknown UUID returns 404
it('unknown uuid returns 404', function () {
    $this->get('/pay/00000000-0000-0000-0000-000000000000')->assertStatus(404);
});

// CLIENT-02: Brand props are passed to Pay page
it('brand props are passed to ClientPayment/Pay component', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    mockStripeClient();
    $this->get("/pay/{$payment->uuid}")
         ->assertInertia(fn ($page) => $page
             ->component('ClientPayment/Pay')
             ->has('brand.name')
             ->has('brand.primary_color')
             ->has('brand.secondary_color')
             ->has('brand.logo_url')
         );
});

// CLIENT-03 + CLIENT-04: clientSecret and publishable_key in props; secret_key absent
it('clientSecret and publishable_key are in props and secret_key is not exposed', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    mockStripeClient();
    $this->get("/pay/{$payment->uuid}")
         ->assertInertia(fn ($page) => $page
             ->component('ClientPayment/Pay')
             ->has('clientSecret')
             ->has('stripeAccount.publishable_key')
             ->missing('stripeAccount.secret_key')
         );
});

// D-02: stripe_payment_intent_id stored after show()
it('stripe_payment_intent_id is stored on the payment after page load', function () {
    $payment = Payment::factory()->create(['status' => 'pending', 'stripe_payment_intent_id' => null]);
    mockStripeClient();
    $this->get("/pay/{$payment->uuid}");
    expect($payment->fresh()->stripe_payment_intent_id)->toBe('pi_test_mock123');
});

// D-03: Completed payment renders Unavailable, not Pay
it('completed payment renders ClientPayment/Unavailable not Pay', function () {
    $payment = Payment::factory()->create(['status' => 'completed']);
    $this->get("/pay/{$payment->uuid}")
         ->assertInertia(fn ($page) => $page->component('ClientPayment/Unavailable'));
});

// D-03: Cancelled payment renders Unavailable
it('cancelled payment renders ClientPayment/Unavailable', function () {
    $payment = Payment::factory()->create(['status' => 'cancelled']);
    $this->get("/pay/{$payment->uuid}")
         ->assertInertia(fn ($page) => $page->component('ClientPayment/Unavailable'));
});

// CLIENT-06: Success page renders when redirect_status=succeeded
it('success page renders when redirect_status is succeeded', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    $this->get("/pay/{$payment->uuid}/success?redirect_status=succeeded")
         ->assertInertia(fn ($page) => $page->component('ClientPayment/Success'));
});

// CLIENT-06: Success redirects to failed when redirect_status is not succeeded
it('success redirects to failed page when redirect_status is not succeeded', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    $this->get("/pay/{$payment->uuid}/success?redirect_status=failed")
         ->assertRedirect("/pay/{$payment->uuid}/failed");
});

// CLIENT-07: Failed page renders with brand props
it('failed page renders with brand props', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    $this->get("/pay/{$payment->uuid}/failed")
         ->assertStatus(200)
         ->assertInertia(fn ($page) => $page
             ->component('ClientPayment/Failed')
             ->has('brand.name')
         );
});

// SEC-04: clientSecret not re-exposed in success page props
it('success controller accepts but does not re-expose client_secret query param', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    // Stripe redirects with payment_intent_client_secret in URL — controller must only read redirect_status
    $this->get("/pay/{$payment->uuid}/success?redirect_status=succeeded&payment_intent_client_secret=pi_mock_secret_xyz")
         ->assertStatus(200)
         ->assertInertia(fn ($page) => $page
             ->component('ClientPayment/Success')
             ->missing('clientSecret')  // must not be passed to Success page props
         );
});

// D-03: Non-pending payment does NOT create PaymentIntent (no StripeClient call)
it('non-pending payment does not call StripeClient', function () {
    $payment = Payment::factory()->create(['status' => 'completed']);
    // No mockStripeClient() — if controller calls StripeClient it will throw
    $this->get("/pay/{$payment->uuid}")
         ->assertInertia(fn ($page) => $page->component('ClientPayment/Unavailable'));
    // stripe_payment_intent_id not overwritten
    expect($payment->fresh()->stripe_payment_intent_id)->toBeNull();
});
