<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\User;
use App\Models\WorldpayAccount;
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

function wpPayload(WorldpayAccount $account, array $overrides = []): array
{
    return array_merge([
        'brand_id' => Brand::factory()->create()->id,
        'relationship_manager_id' => RelationshipManager::factory()->create()->id,
        'provider' => 'worldpay',
        'account_id' => $account->id,
        'currency' => 'gbp',
        'amount' => '25.00',
        'client_name' => 'Alice Smith',
        'package' => 'standard',
    ], $overrides);
}

function wpAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('supports gbp only on Worldpay', function () {
    expect(CurrencySupportResolver::supports('worldpay', null, 'gbp'))->toBeTrue()
        ->and(CurrencySupportResolver::supports('worldpay', null, 'usd'))->toBeFalse()
        ->and(CurrencySupportResolver::supports('worldpay', null, 'pkr', true))->toBeFalse();
});

it('creates a gbp Worldpay payment without a client email and with integer pence', function () {
    $account = WorldpayAccount::factory()->create(['prefix' => 'WP']);

    $this->actingAs(wpAdmin())->post('/payments', wpPayload($account))->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('worldpay')
        ->and($payment->worldpay_account_id)->toBe($account->id)
        ->and($payment->amount)->toBe(2500)
        ->and($payment->client_email)->toBeNull()
        ->and($payment->formattedReferenceCode())->toStartWith('WP-')
        ->and($payment->providerAccountName())->toBe($account->account_name);
});

it('rejects usd and pkr for a Worldpay account', function (string $currency) {
    $account = WorldpayAccount::factory()->create();

    $this->actingAs(wpAdmin())->post('/payments', wpPayload($account, ['currency' => $currency]))
        ->assertSessionHasErrors(['currency' => 'This Worldpay account only accepts GBP payments.']);

    expect(Payment::count())->toBe(0);
})->with(['usd', 'pkr']);

it('refuses an inactive Worldpay account', function () {
    $account = WorldpayAccount::factory()->create(['is_active' => false]);

    $this->actingAs(wpAdmin())->post('/payments', wpPayload($account))->assertSessionHasErrors('account_id');
});

it('locks amount, currency, account and brand once a hosted page exists', function () {
    $payment = Payment::factory()->worldpay()->create(['amount' => 2500, 'worldpay_charge_started_at' => now()]);
    $account = $payment->worldpayAccount;

    $this->actingAs(wpAdmin())->patch("/payments/{$payment->uuid}", wpPayload($account, [
        'amount' => '30.00', 'brand_id' => $payment->brand_id, 'relationship_manager_id' => $payment->relationship_manager_id,
    ]))->assertSessionHasErrors('amount');

    expect($payment->fresh()->amount)->toBe(2500);
});

it('blocks deleting a payment that has a hosted page and allows it before one exists', function () {
    $admin = wpAdmin();
    $started = Payment::factory()->worldpay()->create(['worldpay_charge_started_at' => now()]);
    $fresh = Payment::factory()->worldpay()->create();

    $this->actingAs($admin)->delete("/payments/{$started->uuid}");
    expect(Payment::find($started->id))->not->toBeNull();

    $this->actingAs($admin)->delete("/payments/{$fresh->uuid}");
    expect(Payment::find($fresh->id))->toBeNull();
});

it('lets an agent create a payment through their assigned Worldpay account', function () {
    $account = WorldpayAccount::factory()->create();
    $agent = User::factory()->create();
    $agent->assignRole('agent');
    $agent->paymentAccounts()->create(['provider' => 'worldpay', 'account_id' => $account->id, 'currency' => 'gbp']);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent->brands()->attach($brand);
    $agent->relationshipManagers()->attach($rm);

    $this->actingAs($agent)->post('/payments', [
        'brand_id' => $brand->id, 'relationship_manager_id' => $rm->id, 'currency' => 'gbp',
        'amount' => '10.00', 'client_name' => 'Bob', 'package' => 'basic',
    ])->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('worldpay')->and($payment->worldpay_account_id)->toBe($account->id);
});
