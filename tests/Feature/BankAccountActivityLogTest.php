<?php

use App\Models\ActivityLog;
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

function validActivityLogBankAccountPayload(array $overrides = []): array
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

it('writes a created log row when a bank account is created', function () {
    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->post('/bank-accounts', validActivityLogBankAccountPayload())
        ->assertSessionHasNoErrors();

    $account = BankAccount::firstOrFail();

    expect(ActivityLog::count())->toBe(1);

    $log = ActivityLog::first();
    expect($log->action->value)->toBe('created')
        ->and($log->subject_type)->toBe(BankAccount::class)
        ->and($log->subject_id)->toBe($account->id)
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->actor_name)->toBe($admin->name)
        ->and($log->actor_role)->toBe('admin')
        ->and($log->subject_label)->toBe('Test Bank — Test Agency Ltd (GBP)')
        ->and($log->changes)->toBeNull();
});

it('writes an updated log row with the correct diff when a field actually changes', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create([
        'bank_name' => 'Old Bank',
        'account_name' => 'Test Agency Ltd',
        'account_number' => '12345678',
        'currency' => 'gbp',
        'sort_code' => '12-34-56',
    ]);

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload(['bank_name' => 'New Bank'])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::count())->toBe(1);

    $log = ActivityLog::first();
    expect($log->action->value)->toBe('updated');

    $bankNameChange = collect($log->changes)->firstWhere('field', 'bank_name');
    expect($bankNameChange)->not->toBeNull()
        ->and($bankNameChange['before'])->toBe('Old Bank')
        ->and($bankNameChange['after'])->toBe('New Bank')
        ->and($log->description)->toBe('Updated Bank Name');
});

it('joins two changed field labels naturally in the update description', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create(['currency' => 'gbp']);

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => 'New Bank',
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => 'usd',
            'sort_code' => $account->sort_code,
        ])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::first()->description)->toBe('Updated Bank Name and Currency');
});

it('shows a readable USD to PKR label when currency changes to pkr', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create(['currency' => 'usd']);

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => 'pkr',
            'sort_code' => $account->sort_code,
        ])
    )->assertSessionHasNoErrors();

    $log = ActivityLog::first();
    $currencyChange = collect($log->changes)->firstWhere('field', 'currency');

    expect($currencyChange['before'])->toBe('USD')
        ->and($currencyChange['after'])->toBe('PKR')
        ->and($log->description)->toBe('Updated Currency');
});

it('joins three changed field labels naturally in the update description', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create(['currency' => 'gbp']);

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => 'New Bank',
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => 'usd',
            'sort_code' => '99-99-99',
        ])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::first()->description)->toBe('Updated Bank Name, Currency and Sort Code');
});

it('summarizes the update description as a field count when more than 3 fields change', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create(['currency' => 'gbp']);

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => 'New Bank',
            'account_name' => 'New Agency',
            'account_number' => $account->account_number,
            'currency' => 'usd',
            'sort_code' => '99-99-99',
        ])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::first()->description)->toBe('Updated 4 fields');
});

it('describes a pure addition to assigned users by name', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create(['name' => 'Agent Smith'])->assignRole('agent');
    $account = BankAccount::factory()->create();

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => $account->currency->value,
            'sort_code' => $account->sort_code,
            'user_ids' => [$agent->id],
        ])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::first()->description)->toBe('Added Agent Smith to assigned users');
});

it('describes a pure removal from assigned users by name', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create(['name' => 'Agent Smith'])->assignRole('agent');
    $account = BankAccount::factory()->create();
    $account->assignedUsers()->sync([$agent->id]);

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => $account->currency->value,
            'sort_code' => $account->sort_code,
            'user_ids' => [],
        ])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::first()->description)->toBe('Removed Agent Smith from assigned users');
});

it('describes a combined addition and removal of assigned users in one update', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $oldAgent = User::factory()->create(['name' => 'Jane Smith'])->assignRole('agent');
    $newAgent = User::factory()->create(['name' => 'John Doe'])->assignRole('agent');
    $account = BankAccount::factory()->create();
    $account->assignedUsers()->sync([$oldAgent->id]);

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => $account->currency->value,
            'sort_code' => $account->sort_code,
            'user_ids' => [$newAgent->id],
        ])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::first()->description)->toBe('Added John Doe, removed Jane Smith from assigned users');
});

it('falls back to the field-name-list description when assigned_users changes alongside another field', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create(['name' => 'Agent Smith'])->assignRole('agent');
    $account = BankAccount::factory()->create();

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => 'New Bank',
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => $account->currency->value,
            'sort_code' => $account->sort_code,
            'user_ids' => [$agent->id],
        ])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::first()->description)->toBe('Updated Bank Name and Assigned Users');
});

it('writes no log row when an update does not actually change anything', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create([
        'bank_name' => 'Same Bank',
        'account_name' => 'Same Agency',
        'account_number' => '12345678',
        'currency' => 'gbp',
        'sort_code' => '12-34-56',
        'routing_number' => null,
        'iban' => null,
        'swift_bic' => null,
        'bank_address' => null,
        'bank_country' => null,
        'is_active' => true,
    ]);

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => 'Same Bank',
            'account_name' => 'Same Agency',
            'account_number' => '12345678',
            'currency' => 'gbp',
            'sort_code' => '12-34-56',
        ])
    )->assertSessionHasNoErrors();

    expect(ActivityLog::count())->toBe(0);
});

it('produces an assigned_users diff entry when assigned users change', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create(['name' => 'Agent Smith'])->assignRole('agent');
    $account = BankAccount::factory()->create();

    $this->actingAs($admin)->put(
        "/bank-accounts/{$account->id}",
        validActivityLogBankAccountPayload([
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => $account->currency->value,
            'sort_code' => $account->sort_code,
            'user_ids' => [$agent->id],
        ])
    )->assertSessionHasNoErrors();

    $log = ActivityLog::first();
    $usersChange = collect($log->changes)->firstWhere('field', 'assigned_users');

    expect($usersChange)->not->toBeNull()
        ->and($usersChange['before'])->toBe('None')
        ->and($usersChange['after'])->toBe('Agent Smith');
});

it('writes an activated/deactivated log row with an Active/Inactive diff', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create(['is_active' => true]);

    $this->actingAs($admin)->patch("/bank-accounts/{$account->id}/deactivate")
        ->assertRedirect('/bank-accounts');

    $deactivateLog = ActivityLog::first();
    expect($deactivateLog->action->value)->toBe('deactivated');
    $change = collect($deactivateLog->changes)->firstWhere('field', 'is_active');
    expect($change['before'])->toBe('Active')->and($change['after'])->toBe('Inactive');

    $this->actingAs($admin)->patch("/bank-accounts/{$account->id}/activate")
        ->assertRedirect('/bank-accounts');

    $activateLog = ActivityLog::latest('id')->first();
    expect($activateLog->action->value)->toBe('activated');
    $change = collect($activateLog->changes)->firstWhere('field', 'is_active');
    expect($change['before'])->toBe('Inactive')->and($change['after'])->toBe('Active');
});

it('logs deletion before soft-delete and keeps the log readable after', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = BankAccount::factory()->create(['bank_name' => 'Doomed Bank']);

    $this->actingAs($admin)->delete("/bank-accounts/{$account->id}")
        ->assertRedirect('/bank-accounts');

    $this->assertSoftDeleted('bank_accounts', ['id' => $account->id]);

    $log = ActivityLog::first();
    expect($log->action->value)->toBe('deleted')
        ->and($log->subject_id)->toBe($account->id)
        ->and($log->subject_label)->toContain('Doomed Bank');
});

it('allows admin and account roles to view the activity log but denies agent', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $accountUser = User::factory()->create()->assignRole('account');
    $agent = User::factory()->create()->assignRole('agent');

    $this->actingAs($admin)->get('/bank-accounts/activity-log')->assertOk();
    $this->actingAs($accountUser)->get('/bank-accounts/activity-log')->assertOk();
    $this->actingAs($agent)->get('/bank-accounts/activity-log')->assertForbidden();
});

it('filters the activity log by bank_account_id, action, user_id, date range, and search', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $otherAdmin = User::factory()->create()->assignRole('admin');
    $accountA = BankAccount::factory()->create(['bank_name' => 'Alpha Bank']);
    $accountB = BankAccount::factory()->create(['bank_name' => 'Beta Bank']);

    ActivityLog::create([
        'subject_type' => BankAccount::class,
        'subject_id' => $accountA->id,
        'actor_id' => $admin->id,
        'actor_name' => $admin->name,
        'actor_role' => 'admin',
        'action' => 'created',
        'subject_label' => 'Alpha Bank — Agency (GBP)',
        'description' => 'Created bank account',
        'changes' => null,
        'created_at' => now()->subDays(5),
    ]);

    ActivityLog::create([
        'subject_type' => BankAccount::class,
        'subject_id' => $accountB->id,
        'actor_id' => $otherAdmin->id,
        'actor_name' => $otherAdmin->name,
        'actor_role' => 'admin',
        'action' => 'deleted',
        'subject_label' => 'Beta Bank — Agency (USD)',
        'description' => 'Deleted bank account',
        'changes' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($admin)->get("/bank-accounts/activity-log?bank_account_id={$accountA->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.bank_account_id', $accountA->id));

    $this->actingAs($admin)->get('/bank-accounts/activity-log?action=deleted')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'deleted'));

    $this->actingAs($admin)->get("/bank-accounts/activity-log?user_id={$otherAdmin->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.actor_name', $otherAdmin->name));

    $this->actingAs($admin)->get('/bank-accounts/activity-log?from='.now()->subDay()->toDateString())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'deleted'));

    $this->actingAs($admin)->get('/bank-accounts/activity-log?search=Alpha')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.bank_account_id', $accountA->id));
});

it('filters the activity log by status active, inactive, and deleted', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $active = BankAccount::factory()->create(['is_active' => true]);
    $inactive = BankAccount::factory()->create(['is_active' => false]);
    $deleted = BankAccount::factory()->create();
    $deleted->delete();

    foreach ([$active, $inactive, $deleted] as $account) {
        ActivityLog::create([
            'subject_type' => BankAccount::class,
            'subject_id' => $account->id,
            'actor_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_role' => 'admin',
            'action' => 'created',
            'subject_label' => "{$account->bank_name} — {$account->account_name}",
            'description' => 'Created bank account',
            'changes' => null,
            'created_at' => now(),
        ]);
    }

    $this->actingAs($admin)->get('/bank-accounts/activity-log?status=active')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.bank_account_id', $active->id));

    $this->actingAs($admin)->get('/bank-accounts/activity-log?status=inactive')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.bank_account_id', $inactive->id));

    $this->actingAs($admin)->get('/bank-accounts/activity-log?status=deleted')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.bank_account_id', $deleted->id));
});

it('includes soft-deleted accounts in the bankAccountOptions filter prop', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $deleted = BankAccount::factory()->create();
    $deleted->delete();

    $this->actingAs($admin)->get('/bank-accounts/activity-log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'bankAccountOptions',
            fn ($options) => collect($options)->pluck('id')->contains($deleted->id)
        ));
});
