<?php

use App\Models\Brand;
use App\Models\KurvAccount;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\User;
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

function kurvPayload(KurvAccount $account, array $overrides = []): array
{
    return array_merge([
        'brand_id' => Brand::factory()->create()->id,
        'relationship_manager_id' => RelationshipManager::factory()->create()->id,
        'provider' => 'kurv',
        'account_id' => $account->id,
        'currency' => 'usd',
        'amount' => '25.00',
        'client_name' => 'Alice Smith',
        'client_email' => 'alice@example.com',
        'package' => 'standard',
    ], $overrides);
}

function kurvAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

// ---- Currency and email (AC-2) ----

it('supports usd only on Kurv', function () {
    expect(CurrencySupportResolver::supports('kurv', null, 'usd'))->toBeTrue()
        ->and(CurrencySupportResolver::supports('kurv', null, 'gbp'))->toBeFalse()
        ->and(CurrencySupportResolver::supports('kurv', null, 'pkr', true))->toBeFalse();
});

it('creates a usd Kurv payment with the account and integer cents', function () {
    $account = KurvAccount::factory()->create(['prefix' => 'KV']);

    $this->actingAs(kurvAdmin())->post('/payments', kurvPayload($account))->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('kurv')
        ->and($payment->kurv_account_id)->toBe($account->id)
        ->and($payment->amount)->toBe(2500)
        ->and($payment->formattedReferenceCode())->toStartWith('KV-')
        ->and($payment->providerAccountName())->toBe($account->account_name);
});

it('rejects gbp and pkr for a Kurv account', function (string $currency) {
    $account = KurvAccount::factory()->create();

    $this->actingAs(kurvAdmin())->post('/payments', kurvPayload($account, ['currency' => $currency]))
        ->assertSessionHasErrors(['currency' => 'This Kurv account only accepts USD payments.']);

    expect(Payment::count())->toBe(0);
})->with(['gbp', 'pkr']);

it('requires a client email for Kurv payments', function () {
    $account = KurvAccount::factory()->create();

    $this->actingAs(kurvAdmin())->post('/payments', kurvPayload($account, ['client_email' => '']))
        ->assertSessionHasErrors(['client_email' => 'Client email is required for Kurv payments.']);
});

it('refuses an inactive Kurv account', function () {
    $account = KurvAccount::factory()->create(['is_active' => false]);

    $this->actingAs(kurvAdmin())->post('/payments', kurvPayload($account))->assertSessionHasErrors('account_id');
});

// ---- Locks (AC-6) ----

it('locks amount, currency, account and brand once a link exists', function () {
    $payment = Payment::factory()->kurv()->create(['kurv_charge_started_at' => now(), 'kurv_request_id' => '1074000OO']);
    $other = KurvAccount::factory()->create();

    $this->actingAs(kurvAdmin())->patch("/payments/{$payment->uuid}", kurvPayload($payment->kurvAccount, [
        'brand_id' => $payment->brand_id,
        'relationship_manager_id' => $payment->relationship_manager_id,
        'amount' => '99.00',
    ]))->assertSessionHasErrors('amount');

    $this->actingAs(kurvAdmin())->patch("/payments/{$payment->uuid}", kurvPayload($other, [
        'brand_id' => $payment->brand_id,
        'relationship_manager_id' => $payment->relationship_manager_id,
        'amount' => number_format($payment->amount / 100, 2, '.', ''),
    ]))->assertSessionHasErrors('amount');

    expect($payment->fresh()->amount)->toBe($payment->amount);
});

it('blocks deleting a payment with an open link and allows it once the link is closed', function () {
    $payment = Payment::factory()->kurv()->create(['kurv_charge_started_at' => now(), 'kurv_request_id' => '1074000OO']);

    $this->actingAs(kurvAdmin())->delete("/payments/{$payment->uuid}");
    expect(Payment::find($payment->id))->not->toBeNull();

    $payment->update(['kurv_link_closed_at' => now()]);

    $this->actingAs(kurvAdmin())->delete("/payments/{$payment->uuid}");
    expect(Payment::find($payment->id))->toBeNull();
});

it('clears the saved link when an amount change is made after the link closed', function () {
    $payment = Payment::factory()->kurv()->create([
        'relationship_manager_id' => RelationshipManager::factory()->create()->id,
        'amount' => 2500,
        'kurv_charge_started_at' => now(),
        'kurv_request_id' => '1074000OO',
        'kurv_payment_url' => 'https://sandbox-pay.kurv.app/once_off/x',
        'kurv_link_closed_at' => now(),
    ]);

    $this->actingAs(kurvAdmin())->patch("/payments/{$payment->uuid}", kurvPayload($payment->kurvAccount, [
        'brand_id' => $payment->brand_id,
        'relationship_manager_id' => $payment->relationship_manager_id,
        'amount' => '30.00',
    ]))->assertSessionHasNoErrors();

    $fresh = $payment->fresh();
    expect($fresh->amount)->toBe(3000)
        ->and($fresh->kurv_request_id)->toBeNull()
        ->and($fresh->kurv_payment_url)->toBeNull()
        ->and($fresh->kurv_charge_started_at)->toBeNull()
        ->and($fresh->kurv_link_closed_at)->toBeNull();
});

it('clears the Kurv link columns when the account changes', function () {
    $payment = Payment::factory()->kurv()->create([
        'relationship_manager_id' => RelationshipManager::factory()->create()->id,
        'kurv_request_id' => '1074000OO',
        'kurv_payment_id' => 'pay_1',
    ]);
    $other = KurvAccount::factory()->create();

    $this->actingAs(kurvAdmin())->patch("/payments/{$payment->uuid}", kurvPayload($other, [
        'brand_id' => $payment->brand_id,
        'relationship_manager_id' => $payment->relationship_manager_id,
        'amount' => number_format($payment->amount / 100, 2, '.', ''),
    ]))->assertSessionHasNoErrors();

    expect($payment->fresh()->kurv_request_id)->toBeNull()->and($payment->fresh()->kurv_payment_id)->toBeNull();
});
