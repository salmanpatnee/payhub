<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\User;
use App\Models\WhopAccount;
use App\Support\CurrencySupportResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    foreach (['admin', 'agent', 'account'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function whopFormPayload(WhopAccount $account, array $overrides = []): array
{
    return array_merge([
        'brand_id' => Brand::factory()->create()->id,
        'relationship_manager_id' => RelationshipManager::factory()->create()->id,
        'provider' => 'whop',
        'account_id' => $account->id,
        'currency' => 'usd',
        'amount' => '25.00',
        'client_name' => 'Alice Smith',
        'client_email' => 'alice@example.com',
        'package' => 'standard',
    ], $overrides);
}

function adminForWhop(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

// ---- Currency (AC-2) ----

it('supports usd and gbp on Whop and rejects pkr', function () {
    expect(CurrencySupportResolver::supports('whop', null, 'usd'))->toBeTrue()
        ->and(CurrencySupportResolver::supports('whop', null, 'gbp'))->toBeTrue()
        ->and(CurrencySupportResolver::supports('whop', null, 'pkr'))->toBeFalse();
});

it('creates Whop payments in usd and gbp', function (string $currency) {
    $account = WhopAccount::factory()->create();

    $this->actingAs(adminForWhop())->post('/payments', whopFormPayload($account, ['currency' => $currency]))
        ->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('whop')
        ->and($payment->whop_account_id)->toBe($account->id)
        ->and($payment->stripe_account_id)->toBeNull()
        ->and($payment->currency)->toBe($currency)
        ->and($payment->amount)->toBe(2500);
})->with(['usd', 'gbp']);

it('rejects pkr for Whop', function () {
    $account = WhopAccount::factory()->create();

    $this->actingAs(adminForWhop())->post('/payments', whopFormPayload($account, ['currency' => 'pkr']))
        ->assertSessionHasErrors('currency');
});

it('rejects an inactive Whop account', function () {
    $account = WhopAccount::factory()->create(['is_active' => false]);

    $this->actingAs(adminForWhop())->post('/payments', whopFormPayload($account))
        ->assertSessionHasErrors('account_id');
});

// ---- Client email (AC-4) ----

it('requires a client email for Whop payments only', function () {
    $account = WhopAccount::factory()->create();
    $admin = adminForWhop();

    $this->actingAs($admin)->post('/payments', whopFormPayload($account, ['client_email' => null]))
        ->assertSessionHasErrors(['client_email' => 'Client email is required for Whop payments.']);
});

// ---- Agent assignment ----

it('routes an agent with a Whop slot to the Whop account and requires an email', function () {
    $account = WhopAccount::factory()->create();
    $agent = User::factory()->create();
    $agent->assignRole('agent');
    $agent->paymentAccounts()->create(['currency' => 'gbp', 'provider' => 'whop', 'account_id' => $account->id]);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent->brands()->sync([$brand->id]);
    $agent->relationshipManagers()->sync([$rm->id]);

    $payload = ['brand_id' => $brand->id, 'relationship_manager_id' => $rm->id, 'currency' => 'gbp', 'amount' => '10.10', 'client_name' => 'Bob', 'package' => 'basic'];

    $this->actingAs($agent)->post('/payments', $payload)->assertSessionHasErrors('client_email');

    $this->actingAs($agent)->post('/payments', $payload + ['client_email' => 'bob@example.com'])->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('whop')
        ->and($payment->whop_account_id)->toBe($account->id)
        ->and($payment->amount)->toBe(1010);
});

it('lets an admin assign a Whop account to an agent', function () {
    $account = WhopAccount::factory()->create();
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $this->actingAs(adminForWhop())->get('/admin/users/create')
        ->assertInertia(fn ($page) => $page->where('accountsByCurrency.usd', fn ($rows) => collect($rows)->contains(
            fn ($row) => $row['provider'] === 'whop' && $row['id'] === $account->id
        )));
});

// ---- Immutability after the first charge (AC-6) ----

it('blocks amount, currency and account changes once a charge has started', function (string $field, mixed $value) {
    $account = WhopAccount::factory()->create();
    $other = WhopAccount::factory()->create();
    $payment = Payment::factory()->whop()->create([
        'whop_account_id' => $account->id,
        'amount' => 2500,
        'currency' => 'usd',
        'status' => 'pending',
        'whop_charge_started_at' => now(),
    ]);

    $payload = whopFormPayload($account, ['brand_id' => $payment->brand_id]);
    $payload[$field] = $field === 'account_id' ? $other->id : $value;

    $this->actingAs(adminForWhop())->patch("/payments/{$payment->uuid}", $payload)->assertSessionHasErrors('amount');
})->with([
    'amount' => ['amount', '30.00'],
    'currency' => ['currency', 'gbp'],
    'account' => ['account_id', null],
]);

it('still allows editing other fields, and everything before a charge starts', function () {
    $account = WhopAccount::factory()->create();
    $payment = Payment::factory()->whop()->create([
        'whop_account_id' => $account->id,
        'amount' => 2500,
        'currency' => 'usd',
        'status' => 'pending',
    ]);

    $base = whopFormPayload($account, ['brand_id' => $payment->brand_id]);

    $this->actingAs(adminForWhop())->patch("/payments/{$payment->uuid}", array_merge($base, ['amount' => '30.00']))
        ->assertSessionHasNoErrors();
    expect($payment->fresh()->amount)->toBe(3000);

    $payment->update(['whop_charge_started_at' => now()]);

    $this->actingAs(adminForWhop())->patch("/payments/{$payment->uuid}", array_merge($base, ['amount' => '30.00', 'client_name' => 'New Name']))
        ->assertSessionHasNoErrors();
    expect($payment->fresh()->client_name)->toBe('New Name');
});
