<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\RevolutAccount;
use App\Models\User;
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

// The customer checkout creates a Revolut order from the server-side amount and
// returns only the order token — never the secret key, never a client amount.
it('creates a revolut order from the server amount and renders the card field page', function () {
    Http::fake([
        '*/api/orders' => Http::response([
            'id' => 'ord_abc',
            'token' => 'tok_xyz',
            'state' => 'pending',
        ], 201),
    ]);

    $account = RevolutAccount::factory()->create();
    $payment = Payment::factory()->revolut()->create([
        'revolut_account_id' => $account->id,
        'revolut_order_id' => null,
        'status' => 'pending',
        'amount' => 4200,
        'currency' => 'gbp',
    ]);

    $this->get("/pay/{$payment->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ClientPayment/PayRevolut')
            ->where('orderToken', 'tok_xyz')
            ->where('mode', 'sandbox')
            ->missing('revolutAccount.secret_key')
        );

    // The order id is persisted for the webhook to look up later.
    expect($payment->refresh()->revolut_order_id)->toBe('ord_abc');

    // Amount + currency sent to Revolut come from the DB, in minor units.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/orders')
        && $request['amount'] === 4200
        && $request['currency'] === 'GBP'
        && $request['capture_mode'] === 'automatic');
});

// The order carries the reference code under merchant_order_data.reference (which
// populates the transactions CSV's merchant_order_ext_ref column) plus metadata
// mirroring the Stripe PaymentIntent — the correlation key for reconciliation.
it('sends reference code and payment uuid in merchant_order_data', function () {
    Http::fake([
        '*/api/orders' => Http::response([
            'id' => 'ord_meta',
            'token' => 'tok_meta',
            'state' => 'pending',
        ], 201),
    ]);

    $account = RevolutAccount::factory()->create();
    $payment = Payment::factory()->revolut()->create([
        'revolut_account_id' => $account->id,
        'revolut_order_id' => null,
        'status' => 'pending',
        'reference_code' => 123,
    ]);

    $this->get("/pay/{$payment->uuid}")->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/orders')
        && $request['merchant_order_data']['reference'] === '#000123'
        && $request['merchant_order_data']['metadata']['reference_code'] === '#000123'
        && $request['merchant_order_data']['metadata']['payment_uuid'] === $payment->uuid);
});

// An order already awaiting payment is reused — no duplicate order is created.
it('reuses an existing payable revolut order', function () {
    Http::fake([
        '*/api/orders/ord_existing' => Http::response([
            'id' => 'ord_existing',
            'token' => 'tok_reused',
            'state' => 'pending',
        ], 200),
    ]);

    $account = RevolutAccount::factory()->create();
    $payment = Payment::factory()->revolut()->create([
        'revolut_account_id' => $account->id,
        'revolut_order_id' => 'ord_existing',
        'status' => 'pending',
    ]);

    $this->get("/pay/{$payment->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orderToken', 'tok_reused'));

    expect($payment->refresh()->revolut_order_id)->toBe('ord_existing');
});

// Admin can create a payment routed through a Revolut account; the provider and
// FK columns are set from the unified selector, and the Stripe column stays null.
it('admin can create a revolut payment via the unified account selector', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = RevolutAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $this->actingAs($admin)->post('/payments', [
        'brand_id' => $brand->id,
        'provider' => 'revolut',
        'account_id' => $account->id,
        'relationship_manager_id' => $rm->id,
        'currency' => 'gbp',
        'amount' => '50.00',
    ])->assertRedirect();

    $payment = Payment::first();
    expect($payment->provider->value)->toBe('revolut');
    expect($payment->revolut_account_id)->toBe($account->id);
    expect($payment->stripe_account_id)->toBeNull();
    expect($payment->amount)->toBe(5000);
});

// Inactive Revolut accounts are rejected by the unified account validation.
it('rejects an inactive revolut account on payment creation', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = RevolutAccount::factory()->create(['is_active' => false]);
    $rm = RelationshipManager::factory()->create();

    $this->actingAs($admin)->post('/payments', [
        'brand_id' => $brand->id,
        'provider' => 'revolut',
        'account_id' => $account->id,
        'relationship_manager_id' => $rm->id,
        'currency' => 'gbp',
        'amount' => '50.00',
    ])->assertSessionHasErrors('account_id');

    expect(Payment::count())->toBe(0);
});
