<?php

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

// Regression test for a live-verify finding (2026-09-17): showClover() called
// createCheckoutSession() with no customer object at all, which Clover's real
// Hosted Checkout API rejects outright with 404 "Customer can't be null".
it('sends a customer object built from the payment client name and email', function () {
    Http::fake([
        '*/invoicingcheckoutservice/v1/checkouts' => Http::response([
            'checkoutSessionId' => 'sess_abc',
            'href' => 'https://sandbox.dev.clover.com/pay-checkout/sess_abc?mode=checkout',
            'createdTime' => now()->toIso8601String(),
            'expirationTime' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
    ]);

    $payment = Payment::factory()->clover()->create([
        'status' => 'pending',
        'client_name' => 'Jane Verify',
        'client_email' => 'jane.verify@example.com',
        'clover_checkout_session_id' => null,
        'clover_checkout_url' => null,
        'clover_checkout_expires_at' => null,
    ]);

    $this->get("/pay/{$payment->uuid}")->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/invoicingcheckoutservice/v1/checkouts')
        && $request['customer']['firstName'] === 'Jane'
        && $request['customer']['lastName'] === 'Verify'
        && $request['customer']['email'] === 'jane.verify@example.com');
});

// Clover returns expirationTime as an ISO 8601 string ("2026-09-17T09:52:05.377Z"),
// not a Unix timestamp. Casting it with (int) truncated it to a handful of leading
// digits and produced a near-epoch date; the stored value must land genuinely in
// the future, including in a non-UTC APP_TIMEZONE (Eloquent's datetime cast does
// not itself localize an already-timezoned Carbon instance before persisting it).
it('stores a genuinely future clover_checkout_expires_at from a real-shaped ISO 8601 response', function () {
    $expiresAt = now()->addMinutes(15);

    Http::fake([
        '*/invoicingcheckoutservice/v1/checkouts' => Http::response([
            'checkoutSessionId' => 'sess_abc',
            'href' => 'https://sandbox.dev.clover.com/pay-checkout/sess_abc?mode=checkout',
            'createdTime' => now()->toIso8601String(),
            'expirationTime' => $expiresAt->clone()->utc()->toIso8601String(),
        ], 200),
    ]);

    $payment = Payment::factory()->clover()->create([
        'status' => 'pending',
        'clover_checkout_session_id' => null,
        'clover_checkout_url' => null,
        'clover_checkout_expires_at' => null,
    ]);

    $this->get("/pay/{$payment->uuid}")->assertOk();

    $payment->refresh();

    expect($payment->clover_checkout_expires_at->isFuture())->toBeTrue()
        ->and($payment->clover_checkout_expires_at->diffInSeconds($expiresAt, true))->toBeLessThan(5);
});

// AC-4: a second visit within the session's lifetime must reuse the stored session,
// never call Clover again to create a new one.
it('reuses the stored checkout session on a second visit instead of creating a new one', function () {
    Http::fake([
        '*/invoicingcheckoutservice/v1/checkouts' => Http::response([
            'checkoutSessionId' => 'sess_should_not_be_called_again',
            'href' => 'https://sandbox.dev.clover.com/pay-checkout/should-not-happen?mode=checkout',
            'expirationTime' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
    ]);

    $payment = Payment::factory()->clover()->create([
        'status' => 'pending',
        'clover_checkout_session_id' => 'sess_existing',
        'clover_checkout_url' => 'https://sandbox.dev.clover.com/pay-checkout/sess_existing?mode=checkout',
        'clover_checkout_expires_at' => now()->addMinutes(10),
    ]);

    $this->get("/pay/{$payment->uuid}")
        ->assertInertia(fn ($page) => $page->where('checkoutUrl', 'https://sandbox.dev.clover.com/pay-checkout/sess_existing?mode=checkout'));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/invoicingcheckoutservice/v1/checkouts'));

    expect($payment->refresh()->clover_checkout_session_id)->toBe('sess_existing');
});
