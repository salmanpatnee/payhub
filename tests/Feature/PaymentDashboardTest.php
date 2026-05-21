<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

// DASH-01: Admin can view all payments across all brands
it('admin can view all payments across all brands', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $brand1 = Brand::factory()->create();
    $brand2 = Brand::factory()->create();
    $account1 = StripeAccount::factory()->create();
    $account2 = StripeAccount::factory()->create();

    Payment::factory()->for($brand1)->for($account1, 'stripeAccount')->create();
    Payment::factory()->for($brand2)->for($account2, 'stripeAccount')->create();

    $this->actingAs($admin)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('payments.data', 2));
});

// DASH-04: Payment list includes required columns
it('payment list includes required columns', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $brand = Brand::factory()->create(['name' => 'Test Brand']);
    $account = StripeAccount::factory()->create(['account_name' => 'Test Account']);

    Payment::factory()
        ->for($brand)
        ->for($account, 'stripeAccount')
        ->create([
            'amount' => 5000,
            'currency' => 'usd',
            'status' => 'pending',
            'client_email' => 'client@example.com',
        ]);

    $this->actingAs($admin)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.amount', 5000)
            ->where('payments.data.0.currency', 'usd')
            ->where('payments.data.0.brand_name', 'Test Brand')
            ->where('payments.data.0.status', 'pending')
            ->where('payments.data.0.client_email', 'client@example.com')
            ->has('payments.data.0.created_at')
        );
});

// DASH-02: Admin can filter payments by status
it('admin can filter payments by status', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create();

    Payment::factory()->for($brand)->for($account, 'stripeAccount')->create(['status' => 'completed']);
    Payment::factory()->for($brand)->for($account, 'stripeAccount')->create(['status' => 'pending']);

    $this->actingAs($admin)
        ->get(route('payments.index', ['status' => 'completed']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.status', 'completed')
        );
});

// DASH-02: Admin can filter payments by brand
it('admin can filter payments by brand', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $brand1 = Brand::factory()->create();
    $brand2 = Brand::factory()->create();
    $account = StripeAccount::factory()->create();

    Payment::factory()->for($brand1)->for($account, 'stripeAccount')->create();
    Payment::factory()->for($brand2)->for($account, 'stripeAccount')->create();

    $this->actingAs($admin)
        ->get(route('payments.index', ['brand_id' => $brand1->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.brand_name', $brand1->name)
        );
});

// DASH-02: Admin can filter payments by date range
it('admin can filter payments by date range', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create();

    Payment::factory()->for($brand)->for($account, 'stripeAccount')->create(['created_at' => now()->subDays(10)]);
    Payment::factory()->for($brand)->for($account, 'stripeAccount')->create(['created_at' => now()]);

    $yesterday = now()->subDay()->toDateString();

    $this->actingAs($admin)
        ->get(route('payments.index', ['from' => $yesterday]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('payments.data', 1));
});

// DASH-02: Admin sees brands and accounts props for filter dropdowns
it('admin sees brands and accounts props for filters', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    Brand::factory()->create();
    StripeAccount::factory()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('brands')
            ->has('accounts')
            ->where('isAdmin', true)
        );
});

// DASH-03: User only sees own payments regardless of status filter
it('user only sees own payments regardless of status filter', function () {
    $user1 = User::factory()->create();
    $user1->syncRoles(['user']);

    $user2 = User::factory()->create();
    $user2->syncRoles(['user']);

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create();

    Payment::factory()->for($brand)->for($account, 'stripeAccount')->create([
        'user_id' => $user1->id,
        'status' => 'completed',
    ]);
    Payment::factory()->for($brand)->for($account, 'stripeAccount')->create([
        'user_id' => $user2->id,
        'status' => 'completed',
    ]);

    $this->actingAs($user1)
        ->get(route('payments.index', ['status' => 'completed']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('payments.data', 1));
});

// DASH-03: User does not receive brands or accounts props
it('user does not receive brands or accounts props', function () {
    $user = User::factory()->create();
    $user->syncRoles(['user']);

    $this->actingAs($user)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('isAdmin', false)
            ->where('brands', [])
            ->where('accounts', [])
        );
});

// DASH-02: Filters prop is returned matching query params
it('filters prop is returned matching query params', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->get(route('payments.index', ['status' => 'pending', 'from' => '2026-01-01']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'pending')
            ->where('filters.from', '2026-01-01')
        );
});
