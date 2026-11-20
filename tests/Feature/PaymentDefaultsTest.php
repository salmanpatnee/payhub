<?php

use App\Models\Brand;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

function adminUser(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('passes the configured default brand and account to the form', function () {
    $brand = Brand::factory()->create(['slug' => 'acme']);
    $account = StripeAccount::factory()->create(['is_active' => true]);

    config([
        'payments.defaults.brand' => 'acme',
        'payments.defaults.account' => "stripe:{$account->id}",
    ]);

    $this->actingAs(adminUser())
        ->get('/payments/create')
        ->assertInertia(fn (Assert $page) => $page
            ->component('payments/Create')
            ->where('defaults.brand_id', $brand->id)
            ->where('defaults.account', "stripe:{$account->id}"));
});

it('has no defaults when nothing is configured', function () {
    Brand::factory()->create();

    config(['payments.defaults.brand' => null, 'payments.defaults.account' => null]);

    $this->actingAs(adminUser())
        ->get('/payments/create')
        ->assertInertia(fn (Assert $page) => $page
            ->where('defaults.brand_id', null)
            ->where('defaults.account', null));
});

it('ignores a default brand or account that does not exist or is inactive', function () {
    $inactive = StripeAccount::factory()->create(['is_active' => false]);

    config([
        'payments.defaults.brand' => 'no-such-brand',
        'payments.defaults.account' => "stripe:{$inactive->id}",
    ]);

    $this->actingAs(adminUser())
        ->get('/payments/create')
        ->assertInertia(fn (Assert $page) => $page
            ->where('defaults.brand_id', null)
            ->where('defaults.account', null));
});

it('gives an agent a brand default only when assigned, and never an account default', function () {
    $assigned = Brand::factory()->create(['slug' => 'assigned']);
    $other = Brand::factory()->create(['slug' => 'other']);
    $account = StripeAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $agent = User::factory()->create();
    $agent->assignRole('agent');
    $agent->paymentAccounts()->create(['currency' => 'usd', 'provider' => 'stripe', 'account_id' => $account->id]);
    $agent->brands()->sync([$assigned->id]);
    $agent->relationshipManagers()->sync([$rm->id]);

    config([
        'payments.defaults.brand' => 'assigned',
        'payments.defaults.account' => "stripe:{$account->id}",
    ]);

    $this->actingAs($agent)
        ->get('/payments/create')
        ->assertInertia(fn (Assert $page) => $page
            ->where('defaults.brand_id', $assigned->id)
            ->where('defaults.account', null));

    config(['payments.defaults.brand' => 'other']);

    $this->actingAs($agent)
        ->get('/payments/create')
        ->assertInertia(fn (Assert $page) => $page->where('defaults.brand_id', null));
});
