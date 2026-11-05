<?php

use App\Exports\PaymentsExport;
use App\Models\Brand;
use App\Models\KurvAccount;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    foreach (['admin', 'agent', 'account'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

// ---- CSV export (AC-3) ----

it('exports a Kurv payment with its provider, account and payment id', function () {
    $account = KurvAccount::factory()->create(['account_name' => 'Kurv Main', 'prefix' => 'KV']);
    $payment = Payment::factory()->kurv()->create(['kurv_account_id' => $account->id, 'kurv_request_id' => '1074096OO', 'kurv_payment_id' => 'pay_9']);

    $row = (new PaymentsExport(Payment::query()))->map($payment->load(['brand', 'kurvAccount', 'relationshipManager']));

    expect($row)->toContain('Kurv')->toContain('Kurv Main')->toContain('pay_9')->and($row[0])->toStartWith('KV-');
});

it('falls back to the Kurv request id when no payment id exists yet', function () {
    $payment = Payment::factory()->kurv()->create(['kurv_request_id' => '1074096OO', 'kurv_payment_id' => null]);

    $row = (new PaymentsExport(Payment::query()))->map($payment->load(['brand', 'kurvAccount', 'relationshipManager']));

    expect($row)->toContain('1074096OO');
});

// ---- Dashboard (AC-3) ----

it('shows a Kurv account in Accounts Today with its accepted total', function () {
    $account = KurvAccount::factory()->create(['account_name' => 'Kurv Main']);
    Payment::factory()->kurv()->create([
        'kurv_account_id' => $account->id,
        'amount' => 4200,
        'currency' => 'usd',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $row = collect(DashboardMetrics::for([])['accountsToday'])->firstWhere('provider', 'kurv');

    expect($row)->not->toBeNull()
        ->and($row['name'])->toBe('Kurv Main')
        ->and($row['accepted'])->toEqualCanonicalizing(['usd' => 4200]);
});

it('filters the dashboard by the Kurv provider and by one Kurv account', function () {
    $account = KurvAccount::factory()->create();
    $other = KurvAccount::factory()->create();
    Payment::factory()->kurv()->create(['kurv_account_id' => $account->id, 'amount' => 1000, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->kurv()->create(['kurv_account_id' => $other->id, 'amount' => 2000, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->create(['amount' => 5000, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);

    $byProvider = collect(DashboardMetrics::for(['provider' => 'kurv'])['accountsToday']);
    $byAccount = collect(DashboardMetrics::for(['account' => "kurv:{$account->id}"])['accountsToday']);

    expect($byProvider->pluck('provider')->unique()->all())->toBe(['kurv'])
        ->and($byProvider)->toHaveCount(2)
        ->and($byAccount)->toHaveCount(1)
        ->and($byAccount->first()['accepted'])->toEqualCanonicalizing(['usd' => 1000]);
});

it('lists Kurv accounts in the dashboard account filter and accepts the provider filter', function () {
    KurvAccount::factory()->create(['account_name' => 'Kurv Main']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/dashboard?provider=kurv')
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('filterOptions.accounts', fn ($accounts) => collect($accounts)->contains(fn ($a) => $a['provider'] === 'kurv' && $a['name'] === 'Kurv Main')));
});

// ---- Payments list (AC-3) ----

it('filters the payments list by the Kurv provider', function () {
    $kurv = Payment::factory()->kurv()->create();
    Payment::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/payments?provider=kurv')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.provider', 'kurv')
            ->where('payments.data.0.uuid', $kurv->uuid));
});

it('lets an admin open a Kurv payment and edit form without an error', function () {
    $payment = Payment::factory()->kurv()->create(['kurv_request_id' => '1074096OO']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get("/payments/{$payment->uuid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payment.provider', 'kurv')
            ->where('payment.provider_label', 'Kurv')
            ->where('payment.provider_reference', '1074096OO'));

    $this->actingAs($admin)->get("/payments/{$payment->uuid}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payment.provider', 'kurv')
            ->where('payment.account_id', $payment->kurv_account_id)
            ->where('accounts', fn ($accounts) => collect($accounts)->contains(fn ($a) => $a['provider'] === 'kurv' && $a['currency'] === 'usd')));
});

// ---- Central summary (AC-3) ----

it('counts Kurv payments in the central summary', function () {
    config([
        'central.token_hash_current' => hash('sha256', 'kurv-token'),
        'central.token_hash_next' => null,
        'central.require_https' => false,
    ]);
    Payment::factory()->kurv()->create(['status' => 'completed', 'currency' => 'usd', 'amount' => 1500, 'paid_at' => now()]);

    $this->getJson('/api/central/v1/summary', ['Authorization' => 'Bearer kurv-token'])
        ->assertOk()
        ->assertJsonPath('windows.all_time.links.completed', 1)
        ->assertJsonPath('windows.all_time.money.completed.usd', 1500);
});

// ---- Agent assignment (AC-3) ----

it('lets an agent with a USD Kurv slot create a Kurv payment without choosing an account', function () {
    $account = KurvAccount::factory()->create();
    $agent = User::factory()->create();
    $agent->assignRole('agent');
    $agent->paymentAccounts()->create(['currency' => 'usd', 'provider' => 'kurv', 'account_id' => $account->id]);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent->brands()->attach($brand);
    $agent->relationshipManagers()->attach($rm);

    $this->actingAs($agent)->post('/payments', [
        'brand_id' => $brand->id,
        'relationship_manager_id' => $rm->id,
        'currency' => 'usd',
        'amount' => '20.00',
        'client_name' => 'Alice',
        'client_email' => 'alice@example.com',
    ])->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('kurv')->and($payment->kurv_account_id)->toBe($account->id);
});
