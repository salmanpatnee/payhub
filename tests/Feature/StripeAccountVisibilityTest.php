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

/**
 * Build a fully-mapped agent who owns a payment against the given account.
 */
function makeMappedAgent(StripeAccount $account): User
{
    $agent = User::factory()->create();
    $agent->assignRole('agent');
    $agent->stripe_account_id = $account->id;
    $agent->save();

    return $agent;
}

// Agent index: account_name is stripped and the accounts filter is empty.
it('hides the stripe account name from agents on the index', function () {
    $account = StripeAccount::factory()->create(['is_active' => true, 'account_name' => 'Secret Brand']);
    $agent = makeMappedAgent($account);

    Payment::factory()
        ->for(Brand::factory()->create())
        ->for($account, 'stripeAccount')
        ->create(['user_id' => $agent->id]);

    $this->actingAs($agent)->get('/payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewStripeAccount', false)
            ->where('payments.data.0.account_name', null)
            ->where('accounts', [])
        );
});

// Admin index: account_name is present and the accounts filter is populated.
it('shows the stripe account name to admins on the index', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $account = StripeAccount::factory()->create(['is_active' => true, 'account_name' => 'Secret Brand']);

    Payment::factory()
        ->for(Brand::factory()->create())
        ->for($account, 'stripeAccount')
        ->create(['user_id' => $admin->id]);

    $this->actingAs($admin)->get('/payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewStripeAccount', true)
            ->where('payments.data.0.account_name', 'Secret Brand')
            ->has('accounts', 1)
        );
});

// Account index: account_name is present and the accounts filter is populated.
it('shows the stripe account name to the account role on the index', function () {
    $accountUser = User::factory()->create();
    $accountUser->assignRole('account');

    $account = StripeAccount::factory()->create(['is_active' => true, 'account_name' => 'Secret Brand']);

    Payment::factory()
        ->for(Brand::factory()->create())
        ->for($account, 'stripeAccount')
        ->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($accountUser)->get('/payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewStripeAccount', true)
            ->where('payments.data.0.account_name', 'Secret Brand')
            ->has('accounts', 1)
        );
});

// Agent show page: account_name is null and the flag is false.
it('hides the stripe account name from agents on the show page', function () {
    $account = StripeAccount::factory()->create(['is_active' => true, 'account_name' => 'Secret Brand']);
    $agent = makeMappedAgent($account);

    $payment = Payment::factory()
        ->for(Brand::factory()->create())
        ->for($account, 'stripeAccount')
        ->create(['user_id' => $agent->id]);

    $this->actingAs($agent)->get(route('payments.show', $payment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewStripeAccount', false)
            ->where('payment.account_name', null)
        );
});

// Admin show page: account_name is present and the flag is true.
it('shows the stripe account name to admins on the show page', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $account = StripeAccount::factory()->create(['is_active' => true, 'account_name' => 'Secret Brand']);

    $payment = Payment::factory()
        ->for(Brand::factory()->create())
        ->for($account, 'stripeAccount')
        ->create(['user_id' => $admin->id]);

    $this->actingAs($admin)->get(route('payments.show', $payment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewStripeAccount', true)
            ->where('payment.account_name', 'Secret Brand')
        );
});

// Agent create page: accounts payload carries the id + provider only, never the name.
it('omits the stripe account name from the agent create form options', function () {
    $account = StripeAccount::factory()->create(['is_active' => true, 'account_name' => 'Secret Brand']);
    $agent = makeMappedAgent($account);

    $agent->brands()->sync([Brand::factory()->create()->id]);
    $agent->relationshipManagers()->sync([RelationshipManager::factory()->create()->id]);

    $this->actingAs($agent)->get('/payments/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isAccountLocked', true)
            ->has('accounts', 1)
            ->where('accounts.0.id', $account->id)
            ->where('accounts.0.provider', 'stripe')
            ->missing('accounts.0.account_name')
        );
});

// Regression: agents still store against their assigned stripe account.
it('still saves the agent payment with their assigned stripe account', function () {
    $assigned = StripeAccount::factory()->create(['is_active' => true]);
    $other = StripeAccount::factory()->create(['is_active' => true]);
    $agent = makeMappedAgent($assigned);

    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent->brands()->sync([$brand->id]);
    $agent->relationshipManagers()->sync([$rm->id]);

    // Even if a forged account id is posted, the controller forces the assigned one.
    $this->actingAs($agent)->post('/payments', [
        'brand_id' => $brand->id,
        'provider' => 'stripe',
        'account_id' => $other->id,
        'relationship_manager_id' => $rm->id,
        'currency' => 'usd',
        'amount' => '25.00',
        'client_name' => 'Alice Smith',
    ])->assertRedirect();

    expect(Payment::count())->toBe(1);
    expect(Payment::first()->stripe_account_id)->toBe($assigned->id);
});
