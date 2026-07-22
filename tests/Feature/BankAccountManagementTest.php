<?php

use App\Models\BankAccount;
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

function validBankAccountPayload(array $overrides = []): array
{
    return array_merge([
        'bank_name' => 'Test Bank',
        'account_name' => 'Test Agency Ltd',
        'account_number' => '12345678',
        'currency' => 'gbp',
        'sort_code' => '12-34-56',
        'routing_number' => '',
        'iban' => '',
        'swift_bic' => '',
        'bank_address' => '',
        'bank_country' => '',
        'is_active' => true,
        'user_ids' => [],
    ], $overrides);
}

// Admin can view the index
it('admin can view the bank accounts index', function () {
    $admin = User::factory()->create()->assignRole('admin');
    BankAccount::factory()->create();

    $this->actingAs($admin)->get('/bank-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', true)->where('isAgent', false));
});

// Admin can create a bank account
it('admin can create a bank account', function () {
    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->post('/bank-accounts', validBankAccountPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect('/bank-accounts');

    $this->assertDatabaseHas('bank_accounts', ['bank_name' => 'Test Bank']);
});

// Admin can update a bank account
it('admin can update a bank account', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create(['bank_name' => 'Old Bank']);

    $this->actingAs($admin)->put("/bank-accounts/{$account->id}", validBankAccountPayload(['bank_name' => 'New Bank']))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/bank-accounts');

    $this->assertDatabaseHas('bank_accounts', ['id' => $account->id, 'bank_name' => 'New Bank']);
});

// Admin can soft-delete a bank account
it('admin can soft-delete a bank account', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create();

    $this->actingAs($admin)->delete("/bank-accounts/{$account->id}")
        ->assertRedirect('/bank-accounts');

    $this->assertSoftDeleted('bank_accounts', ['id' => $account->id]);
});

// Account-role user can view/create/update/soft-delete a bank account
it('account role user can view the bank accounts index', function () {
    $accountUser = User::factory()->create()->assignRole('account');
    BankAccount::factory()->create();

    $this->actingAs($accountUser)->get('/bank-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', true)->where('isAgent', false));
});

it('account role user can create a bank account', function () {
    $accountUser = User::factory()->create()->assignRole('account');

    $this->actingAs($accountUser)->post('/bank-accounts', validBankAccountPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect('/bank-accounts');

    $this->assertDatabaseHas('bank_accounts', ['bank_name' => 'Test Bank']);
});

it('account role user can update a bank account', function () {
    $accountUser = User::factory()->create()->assignRole('account');
    $account = BankAccount::factory()->create(['bank_name' => 'Old Bank']);

    $this->actingAs($accountUser)->put("/bank-accounts/{$account->id}", validBankAccountPayload(['bank_name' => 'New Bank']))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/bank-accounts');

    $this->assertDatabaseHas('bank_accounts', ['id' => $account->id, 'bank_name' => 'New Bank']);
});

it('account role user can soft-delete a bank account', function () {
    $accountUser = User::factory()->create()->assignRole('account');
    $account = BankAccount::factory()->create();

    $this->actingAs($accountUser)->delete("/bank-accounts/{$account->id}")
        ->assertRedirect('/bank-accounts');

    $this->assertSoftDeleted('bank_accounts', ['id' => $account->id]);
});

// Agent is denied create/update/delete but can view the index
it('agent can view the index but is denied create, update, and delete', function () {
    $agent = User::factory()->create()->assignRole('agent');
    $account = BankAccount::factory()->create();

    $this->actingAs($agent)->get('/bank-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false)->where('isAgent', true)->where('bankAccounts', []));

    $this->actingAs($agent)->get('/bank-accounts/create')->assertForbidden();
    $this->actingAs($agent)->post('/bank-accounts', validBankAccountPayload())->assertForbidden();
    $this->actingAs($agent)->get("/bank-accounts/{$account->id}/edit")->assertForbidden();
    $this->actingAs($agent)->put("/bank-accounts/{$account->id}", validBankAccountPayload())->assertForbidden();
    $this->actingAs($agent)->delete("/bank-accounts/{$account->id}")->assertForbidden();

    $this->assertDatabaseHas('bank_accounts', ['id' => $account->id]);
});

// Agent's myAccounts list only contains accounts assigned to them, excluding inactive ones
it('agent only sees active bank accounts assigned to them', function () {
    $agent = User::factory()->create()->assignRole('agent');
    $otherAgent = User::factory()->create()->assignRole('agent');

    $assignedActive = BankAccount::factory()->create(['is_active' => true]);
    $assignedInactive = BankAccount::factory()->create(['is_active' => false]);
    $notAssigned = BankAccount::factory()->create(['is_active' => true]);

    $assignedActive->assignedUsers()->attach($agent);
    $assignedInactive->assignedUsers()->attach($agent);
    $notAssigned->assignedUsers()->attach($otherAgent);

    $this->actingAs($agent)->get('/bank-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('myAccounts', 1)
            ->where('myAccounts.0.id', $assignedActive->id)
        );
});

// Assigning/unassigning users via user_ids sync works on both store and update
it('syncs assigned users on store and update', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $userOne = User::factory()->create();
    $userTwo = User::factory()->create();

    $this->actingAs($admin)->post('/bank-accounts', validBankAccountPayload(['user_ids' => [$userOne->id]]))
        ->assertSessionHasNoErrors();

    $account = BankAccount::firstOrFail();
    expect($account->assignedUsers()->pluck('users.id')->all())->toBe([$userOne->id]);

    $this->actingAs($admin)->put("/bank-accounts/{$account->id}", validBankAccountPayload(['user_ids' => [$userTwo->id]]))
        ->assertSessionHasNoErrors();

    $account->refresh();
    expect($account->assignedUsers()->pluck('users.id')->all())->toBe([$userTwo->id]);
});

// Soft-deleted account disappears from an assigned user's bankAccounts() relation
it('soft-deleted bank account disappears from an assigned user relation', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create()->assignRole('agent');
    $account = BankAccount::factory()->create();
    $account->assignedUsers()->attach($agent);

    expect($agent->bankAccounts()->count())->toBe(1);

    $this->actingAs($admin)->delete("/bank-accounts/{$account->id}");

    expect($agent->bankAccounts()->count())->toBe(0);
});

// The assignable "users" options on create/edit only include agent-role users
it('only offers agent-role users as assignable options on create', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create()->assignRole('agent');
    User::factory()->create()->assignRole('account');
    User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->get('/bank-accounts/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users', 1)
            ->where('users.0.id', $agent->id)
        );
});

// Edit still includes an already-assigned user even if no longer an agent
it('edit options include agent-role users plus any already-assigned user', function () {
    $admin = User::factory()->create()->assignRole('admin');
    User::factory()->create()->assignRole('agent');
    $nonAgentAssigned = User::factory()->create()->assignRole('account');
    $account = BankAccount::factory()->create();
    $account->assignedUsers()->attach($nonAgentAssigned);

    $this->actingAs($admin)->get("/bank-accounts/{$account->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users', 2)
            ->where('bankAccount.user_ids', [$nonAgentAssigned->id])
        );
});

// Search matches bank_name or account_name
it('filters the index by search term across bank name and account name', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $match = BankAccount::factory()->create(['bank_name' => 'Barclays', 'account_name' => 'Acme Ltd']);
    BankAccount::factory()->create(['bank_name' => 'HSBC', 'account_name' => 'Other Ltd']);

    $this->actingAs($admin)->get('/bank-accounts?search=Barclays')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bankAccounts', 1)
            ->where('bankAccounts.0.id', $match->id)
        );
});

// Currency filter narrows the index to one currency
it('filters the index by currency', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $usd = BankAccount::factory()->create(['currency' => 'usd']);
    BankAccount::factory()->create(['currency' => 'gbp']);

    $this->actingAs($admin)->get('/bank-accounts?currency=usd')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('bankAccounts', 1)
            ->where('bankAccounts.0.id', $usd->id)
        );
});

// Sorting by account_name in descending order is respected
it('sorts the index by the requested column and direction', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $a = BankAccount::factory()->create(['account_name' => 'Alpha']);
    $z = BankAccount::factory()->create(['account_name' => 'Zulu']);

    $this->actingAs($admin)->get('/bank-accounts?sort=account_name&direction=desc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('bankAccounts.0.id', $z->id)
            ->where('bankAccounts.1.id', $a->id)
        );
});

// At least one of sort_code / routing_number / iban is required
it('requires at least one of sort code, routing number, or iban', function () {
    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->post('/bank-accounts', validBankAccountPayload([
        'sort_code' => '',
        'routing_number' => '',
        'iban' => '',
    ]))->assertSessionHasErrors(['sort_code']);
});
