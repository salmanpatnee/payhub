<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'account', 'guard_name' => 'web']);
});

function makeAccountPayment(?User $owner = null): Payment
{
    return Payment::factory()
        ->for(Brand::factory()->create())
        ->for(StripeAccount::factory()->create(['is_active' => true]), 'stripeAccount')
        ->create(['user_id' => ($owner ?? User::factory()->create())->id]);
}

// Account sees ALL payments on the index, not just their own (no user_id scope)
it('account sees all payments on the index with readOnly prop', function () {
    $account = User::factory()->create()->assignRole('account');

    makeAccountPayment(User::factory()->create()->assignRole('agent'));
    makeAccountPayment(User::factory()->create()->assignRole('admin'));

    $this->actingAs($account)->get('/payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('payments.data', 2)
            ->where('readOnly', true)
            ->where('isAdmin', false)
            ->has('brands')
        );
});

// Account is blocked from the create page
it('account cannot open the payment create page', function () {
    $account = User::factory()->create()->assignRole('account');

    $this->actingAs($account)->get('/payments/create')->assertForbidden();
});

// Account is blocked from storing a payment
it('account cannot store a payment', function () {
    $account = User::factory()->create()->assignRole('account');
    $brand = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $this->actingAs($account)->post('/payments', [
        'brand_id' => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'relationship_manager_id' => $rm->id,
        'currency' => 'usd',
        'amount' => '25.00',
    ])->assertForbidden();

    expect(Payment::count())->toBe(0);
});

// Account cannot open the Show page (not admin, owns nothing → policy view 403)
it('account cannot view an individual payment show page', function () {
    $account = User::factory()->create()->assignRole('account');
    $payment = makeAccountPayment();

    $this->actingAs($account)->get(route('payments.show', $payment))->assertForbidden();
});

// Account cannot edit / update / delete
it('account cannot edit, update, or delete a payment', function () {
    $account = User::factory()->create()->assignRole('account');
    $payment = makeAccountPayment();

    // A valid body so the request passes validation and reaches the update gate.
    $validUpdate = [
        'brand_id' => Brand::factory()->create()->id,
        'stripe_account_id' => StripeAccount::factory()->create(['is_active' => true])->id,
        'relationship_manager_id' => RelationshipManager::factory()->create()->id,
        'currency' => 'usd',
        'amount' => '25.00',
    ];

    $this->actingAs($account)->get("/payments/{$payment->uuid}/edit")->assertForbidden();
    $this->actingAs($account)->patch("/payments/{$payment->uuid}", $validUpdate)->assertForbidden();
    $this->actingAs($account)->delete("/payments/{$payment->uuid}")->assertForbidden();

    expect(Payment::whereKey($payment->id)->exists())->toBeTrue();
});

// Admin can create an account-role user with no mappings
it('admin can create an account user without stripe, brand, or RM mappings', function () {
    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Finance Viewer',
        'username' => 'finance',
        'password' => 'password-123',
        'role' => 'account',
    ])->assertRedirect('/admin/users');

    $user = User::where('username', 'finance')->firstOrFail();
    expect($user->hasRole('account'))->toBeTrue();
    expect($user->stripe_account_id)->toBeNull();
    expect($user->brands()->count())->toBe(0);
    expect($user->relationshipManagers()->count())->toBe(0);
});
