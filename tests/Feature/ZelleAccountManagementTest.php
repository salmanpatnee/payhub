<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\ZelleAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'account', 'guard_name' => 'web']);
});

function validZellePayload(array $overrides = []): array
{
    return array_merge([
        'account_name' => 'Acme Holdings',
        'email' => 'Pay@Acme.test',
        'mobile_number' => '+1 (555) 123-4567',
        'currency' => 'usd',
        'is_active' => true,
        'user_ids' => [],
    ], $overrides);
}

function zelleUser(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

// AC-1, AC-2, AC-4, AC-5: admin full lifecycle
it('lets an admin create, edit, deactivate, reactivate and delete an account', function () {
    $admin = zelleUser('admin');

    $this->actingAs($admin)->post('/zelle-accounts', validZellePayload())
        ->assertRedirect(route('zelle-accounts.index'));

    $account = ZelleAccount::firstOrFail();
    expect($account->email)->toBe('pay@acme.test')
        ->and($account->mobile_number)->toBe('+1 (555) 123-4567')
        ->and($account->is_active)->toBeTrue();

    $this->actingAs($admin)
        ->put("/zelle-accounts/{$account->id}", validZellePayload(['account_name' => 'Renamed', 'currency' => 'gbp']))
        ->assertRedirect(route('zelle-accounts.index'));
    expect($account->fresh()->account_name)->toBe('Renamed')
        ->and($account->fresh()->currency->value)->toBe('gbp');

    $this->actingAs($admin)->patch("/zelle-accounts/{$account->id}/deactivate")->assertRedirect();
    expect($account->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch("/zelle-accounts/{$account->id}/activate")->assertRedirect();
    expect($account->fresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->delete("/zelle-accounts/{$account->id}")
        ->assertRedirect(route('zelle-accounts.index'));
    expect(ZelleAccount::count())->toBe(0)
        ->and(ZelleAccount::withTrashed()->count())->toBe(1);
});

it('lets an account role user create and edit an account', function () {
    $user = zelleUser('account');

    $this->actingAs($user)->post('/zelle-accounts', validZellePayload())->assertRedirect();
    $account = ZelleAccount::firstOrFail();

    $this->actingAs($user)->put("/zelle-accounts/{$account->id}", validZellePayload(['account_name' => 'Changed']))
        ->assertRedirect();
    expect($account->fresh()->account_name)->toBe('Changed');
});

it('renders the create and edit pages for managers', function () {
    $admin = zelleUser('admin');
    $account = ZelleAccount::factory()->create();

    $this->actingAs($admin)->get('/zelle-accounts/create')
        ->assertInertia(fn (Assert $page) => $page->component('zelle-accounts/Create')->has('users'));
    $this->actingAs($admin)->get("/zelle-accounts/{$account->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->component('zelle-accounts/Edit')->has('zelleAccount.user_ids'));
});

// AC-2: unique email, case-insensitive, freed by soft delete
it('rejects a duplicate email in any casing and allows it after soft delete', function () {
    $admin = zelleUser('admin');
    $existing = ZelleAccount::factory()->create(['email' => 'dup@acme.test']);

    $this->actingAs($admin)->post('/zelle-accounts', validZellePayload(['email' => 'DUP@Acme.test']))
        ->assertSessionHasErrors('email');

    $existing->delete();

    $this->actingAs($admin)->post('/zelle-accounts', validZellePayload(['email' => 'DUP@Acme.test']))
        ->assertSessionDoesntHaveErrors();
    expect(ZelleAccount::where('email', 'dup@acme.test')->count())->toBe(1);
});

it('lets an account keep its own email on update', function () {
    $admin = zelleUser('admin');
    $account = ZelleAccount::factory()->create(['email' => 'same@acme.test']);

    $this->actingAs($admin)->put("/zelle-accounts/{$account->id}", validZellePayload(['email' => 'same@acme.test']))
        ->assertSessionDoesntHaveErrors();
});

// AC-1, AC-3: field validation
it('validates required fields, mobile characters and currency', function (array $overrides, string $errorKey) {
    $admin = zelleUser('admin');

    $this->actingAs($admin)->post('/zelle-accounts', validZellePayload($overrides))
        ->assertSessionHasErrors($errorKey);
    expect(ZelleAccount::count())->toBe(0);
})->with([
    'missing name' => [['account_name' => ''], 'account_name'],
    'missing email' => [['email' => ''], 'email'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'letters in mobile' => [['mobile_number' => '555-CALL'], 'mobile_number'],
    'mobile too long' => [['mobile_number' => str_repeat('1', 21)], 'mobile_number'],
    'pkr currency' => [['currency' => 'pkr'], 'currency'],
]);

it('allows an empty mobile number', function () {
    $admin = zelleUser('admin');

    $this->actingAs($admin)->post('/zelle-accounts', validZellePayload(['mobile_number' => '']))
        ->assertSessionDoesntHaveErrors();
    expect(ZelleAccount::firstOrFail()->mobile_number)->toBeNull();
});

// AC-6: assignment
it('assigns agents and rejects users without the agent role', function () {
    $admin = zelleUser('admin');
    $agent = zelleUser('agent');

    $this->actingAs($admin)->post('/zelle-accounts', validZellePayload(['user_ids' => [$agent->id]]))
        ->assertSessionDoesntHaveErrors();
    expect(ZelleAccount::firstOrFail()->assignedUsers()->pluck('users.id')->all())->toBe([$agent->id]);

    $this->actingAs($admin)->post('/zelle-accounts', validZellePayload(['email' => 'other@acme.test', 'user_ids' => [$admin->id]]))
        ->assertSessionHasErrors('user_ids.0');
    expect(ZelleAccount::count())->toBe(1);
});

it('keeps assignments when user_ids is absent and clears them when empty', function () {
    $admin = zelleUser('admin');
    $agent = zelleUser('agent');
    $account = ZelleAccount::factory()->create();
    $account->assignedUsers()->attach($agent);

    $payload = validZellePayload();
    unset($payload['user_ids']);

    $this->actingAs($admin)->put("/zelle-accounts/{$account->id}", $payload)->assertSessionDoesntHaveErrors();
    expect($account->assignedUsers()->count())->toBe(1);

    $this->actingAs($admin)->put("/zelle-accounts/{$account->id}", validZellePayload(['user_ids' => []]))
        ->assertSessionDoesntHaveErrors();
    expect($account->assignedUsers()->count())->toBe(0);
});

it('keeps assignments when an account is deactivated', function () {
    $admin = zelleUser('admin');
    $agent = zelleUser('agent');
    $account = ZelleAccount::factory()->create();
    $account->assignedUsers()->attach($agent);

    $this->actingAs($admin)->patch("/zelle-accounts/{$account->id}/deactivate");

    expect($account->assignedUsers()->count())->toBe(1);
});

// AC-4: agent cannot manage
it('forbids agents from every management action', function () {
    $agent = zelleUser('agent');
    $account = ZelleAccount::factory()->create();

    $this->actingAs($agent)->get('/zelle-accounts/create')->assertForbidden();
    $this->actingAs($agent)->post('/zelle-accounts', validZellePayload())->assertForbidden();
    $this->actingAs($agent)->get("/zelle-accounts/{$account->id}/edit")->assertForbidden();
    $this->actingAs($agent)->put("/zelle-accounts/{$account->id}", validZellePayload())->assertForbidden();
    $this->actingAs($agent)->delete("/zelle-accounts/{$account->id}")->assertForbidden();
    $this->actingAs($agent)->patch("/zelle-accounts/{$account->id}/activate")->assertForbidden();
    $this->actingAs($agent)->patch("/zelle-accounts/{$account->id}/deactivate")->assertForbidden();

    expect(ZelleAccount::count())->toBe(1)
        ->and($account->fresh()->is_active)->toBeTrue();
});

it('returns 403 to agents on store and update before validation runs', function () {
    $agent = zelleUser('agent');
    $account = ZelleAccount::factory()->create();

    $this->actingAs($agent)->post('/zelle-accounts', [])->assertForbidden();
    $this->actingAs($agent)->put("/zelle-accounts/{$account->id}", [])->assertForbidden();
    $this->actingAs($agent)->put("/zelle-accounts/{$account->id}", ['email' => 'not-an-email'])->assertForbidden();
});

it('returns 404 for a soft deleted account', function () {
    $admin = zelleUser('admin');
    $account = ZelleAccount::factory()->create();
    $account->delete();

    $this->actingAs($admin)->get("/zelle-accounts/{$account->id}/edit")->assertNotFound();
});

// AC-7: list, filters, pagination
it('filters the admin list by currency and status', function () {
    $admin = zelleUser('admin');
    ZelleAccount::factory()->create(['currency' => 'usd']);
    ZelleAccount::factory()->create(['currency' => 'gbp']);
    ZelleAccount::factory()->inactive()->create(['currency' => 'gbp']);

    $this->actingAs($admin)->get('/zelle-accounts?currency=gbp&status=active')
        ->assertInertia(fn (Assert $page) => $page->has('zelleAccounts.data', 1));
    $this->actingAs($admin)->get('/zelle-accounts?status=inactive')
        ->assertInertia(fn (Assert $page) => $page->has('zelleAccounts.data', 1));
    $this->actingAs($admin)->get('/zelle-accounts')
        ->assertInertia(fn (Assert $page) => $page->has('zelleAccounts.data', 3));
});

it('paginates 15 per page and keeps filters in page links', function () {
    $admin = zelleUser('admin');
    ZelleAccount::factory()->count(16)->create(['currency' => 'usd']);

    $this->actingAs($admin)->get('/zelle-accounts?currency=usd')
        ->assertInertia(fn (Assert $page) => $page
            ->has('zelleAccounts.data', 15)
            ->where('zelleAccounts.last_page', 2)
            ->where('zelleAccounts.next_page_url', fn ($url) => str_contains($url, 'currency=usd') && str_contains($url, 'page=2')));

    $this->actingAs($admin)->get('/zelle-accounts?currency=usd&page=2')
        ->assertInertia(fn (Assert $page) => $page->has('zelleAccounts.data', 1));
});

// AC-8: search
it('searches by partial email and by mobile number ignoring punctuation', function () {
    $admin = zelleUser('admin');
    ZelleAccount::factory()->create(['email' => 'alice@acme.test', 'mobile_number' => '+1 (555) 123-4567']);
    ZelleAccount::factory()->create(['email' => 'bob@other.test', 'mobile_number' => null]);

    $count = fn (string $search) => $this->actingAs($admin)
        ->get('/zelle-accounts?search='.urlencode($search))
        ->viewData('page')['props']['zelleAccounts']['data'];

    expect($count('ACME'))->toHaveCount(1)
        ->and($count('555-123'))->toHaveCount(1)
        ->and($count('(555) 123 4567'))->toHaveCount(1)
        ->and($count('+1 555'))->toHaveCount(1)
        ->and($count('nomatch'))->toHaveCount(0);
});

it('treats percent and underscore in search as literal characters', function () {
    $admin = zelleUser('admin');
    ZelleAccount::factory()->create(['email' => 'alice@acme.test', 'mobile_number' => null]);
    ZelleAccount::factory()->create(['email' => 'a_b@acme.test', 'mobile_number' => null]);

    $rows = fn (string $search) => $this->actingAs($admin)
        ->get('/zelle-accounts?search='.urlencode($search))
        ->viewData('page')['props']['zelleAccounts']['data'];

    expect($rows('%'))->toHaveCount(0)
        ->and($rows('a_b'))->toHaveCount(1)
        ->and($rows('_'))->toHaveCount(1);
});

// AC-5, AC-9: agent view
it('shows agents only their active, assigned, non deleted accounts', function () {
    $agent = zelleUser('agent');
    $other = zelleUser('agent');

    $visible = ZelleAccount::factory()->create();
    $inactive = ZelleAccount::factory()->inactive()->create();
    $deleted = ZelleAccount::factory()->create();
    $notMine = ZelleAccount::factory()->create();

    $agent->zelleAccounts()->attach([$visible->id, $inactive->id, $deleted->id]);
    $other->zelleAccounts()->attach($notMine->id);
    $deleted->delete();

    $this->actingAs($agent)->get('/zelle-accounts')
        ->assertInertia(fn (Assert $page) => $page
            ->where('isAgent', true)
            ->where('canManage', false)
            ->where('zelleAccounts', null)
            ->has('myAccounts', 1)
            ->where('myAccounts.0.id', $visible->id));

    $inactive->update(['is_active' => true]);

    $this->actingAs($agent)->get('/zelle-accounts')
        ->assertInertia(fn (Assert $page) => $page->has('myAccounts', 2));
});

// AC-11: empty states
it('gives a user with no role an empty page without an error', function () {
    $user = User::factory()->create();
    ZelleAccount::factory()->create();

    $this->actingAs($user)->get('/zelle-accounts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)
            ->where('isAgent', false)
            ->where('zelleAccounts', null)
            ->has('myAccounts', 0));
});

it('gives an agent with no assignments an empty list', function () {
    $agent = zelleUser('agent');

    $this->actingAs($agent)->get('/zelle-accounts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('myAccounts', 0));
});

it('requires authentication', function () {
    $this->get('/zelle-accounts')->assertRedirect();
});

// AC-12: no activity log
it('writes no activity log rows for any Zelle action', function () {
    $admin = zelleUser('admin');

    $this->actingAs($admin)->post('/zelle-accounts', validZellePayload());
    $account = ZelleAccount::firstOrFail();
    $this->actingAs($admin)->put("/zelle-accounts/{$account->id}", validZellePayload(['account_name' => 'X']));
    $this->actingAs($admin)->patch("/zelle-accounts/{$account->id}/deactivate");
    $this->actingAs($admin)->patch("/zelle-accounts/{$account->id}/activate");
    $this->actingAs($admin)->delete("/zelle-accounts/{$account->id}");

    expect(ActivityLog::count())->toBe(0);
});
