<?php

use App\Models\Brand;
use App\Models\Payment;
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
});

it('admin can soft-delete a payment of any status', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'status' => 'completed',
    ]);

    $this->actingAs($admin)
        ->delete("/payments/{$payment->uuid}")
        ->assertRedirect('/payments');

    expect(Payment::find($payment->id))->toBeNull();
    expect(Payment::withTrashed()->find($payment->id)->deleted_at)->not->toBeNull();
});

it('soft-deleted payments are hidden from the index', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'status' => 'pending',
    ]);

    $this->actingAs($admin)->delete("/payments/{$payment->uuid}");

    $this->actingAs($admin)->get('/payments')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->has('payments.data', 0));
});

it('agent cannot delete a payment', function () {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $account = StripeAccount::factory()->create(['is_active' => true]);
    $agent->stripe_account_id = $account->id;
    $agent->save();

    $brand = Brand::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $agent->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'status' => 'pending',
    ]);

    $this->actingAs($agent)
        ->delete("/payments/{$payment->uuid}")
        ->assertForbidden();

    expect(Payment::find($payment->id))->not->toBeNull();
});
