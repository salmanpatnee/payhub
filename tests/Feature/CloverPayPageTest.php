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

// AC-4: the embedded card form is rendered directly on PayHub's own pay page,
// initialized from the account's merchant_id/api_access_key/environment — no
// redirect, and no server-side Clover call is needed just to show the page
// (unlike spec 0001's Hosted Checkout, which had to create a session first).
it('renders the embedded card form with the clover account public props', function () {
    Http::fake();

    $payment = Payment::factory()->clover()->create(['status' => 'pending']);
    $payment->loadMissing('cloverAccount');

    $this->get("/pay/{$payment->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ClientPayment/PayClover')
            ->where('cloverAccount.merchant_id', $payment->cloverAccount->merchant_id)
            ->where('cloverAccount.api_access_key', $payment->cloverAccount->api_access_key)
            ->where('cloverAccount.environment', $payment->cloverAccount->environment)
        );

    // No redirect/session concept (unlike spec 0001's Hosted Checkout) — showing
    // the page never has to call Clover. (The Inertia SSR round-trip itself
    // shows up as a recorded request here, so this checks specifically for a
    // Clover API call rather than asserting nothing was sent at all.)
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'clover.com'));
});

// private_token must never reach the client — only the public api_access_key does.
it('never exposes the clover account private token to the pay page', function () {
    Http::fake();

    $payment = Payment::factory()->clover()->create(['status' => 'pending']);

    $this->get("/pay/{$payment->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('cloverAccount.private_token')
        );
});

// A payment that's already completed shows Unavailable, not the card form —
// mirrors the same D-03/D-12 guard every other provider's pay page has.
it('shows unavailable for an already-completed clover payment', function () {
    $payment = Payment::factory()->clover()->create(['status' => 'completed']);

    $this->get("/pay/{$payment->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ClientPayment/Unavailable')
            ->where('status', 'completed')
        );
});
