<?php

use App\Exports\PaymentsExport;
use App\Models\Payment;
use App\Models\User;
use App\Models\WorldpayAccount;
use App\Models\WorldpayLookupAttempt;
use App\Models\WorldpayPage;
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

function wpParityAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('exports a Worldpay payment with its provider, account and payment id', function () {
    $account = WorldpayAccount::factory()->create(['account_name' => 'WP Main', 'prefix' => 'WP']);
    $payment = Payment::factory()->worldpay()->create(['worldpay_account_id' => $account->id, 'worldpay_payment_id' => 'payXYZ']);

    $row = (new PaymentsExport(Payment::query()))->map($payment->load(['brand', 'worldpayAccount', 'relationshipManager']));

    expect($row)->toContain('Worldpay')->toContain('WP Main')->toContain('payXYZ')->and($row[0])->toStartWith('WP-');
});

it('falls back to the newest page reference when no payment id exists yet', function () {
    $payment = Payment::factory()->worldpay()->create();
    WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => 'ref-one', 'expires_at' => now()->addDay()]);

    $row = (new PaymentsExport(Payment::query()))->map($payment->load(['brand', 'worldpayAccount', 'relationshipManager']));

    expect($row)->toContain('ref-one');
});

it('shows a Worldpay account in Accounts Today with its accepted total', function () {
    $account = WorldpayAccount::factory()->create(['account_name' => 'WP Main']);
    Payment::factory()->worldpay()->create([
        'worldpay_account_id' => $account->id, 'amount' => 4200, 'currency' => 'gbp', 'status' => 'completed', 'paid_at' => now(),
    ]);

    $row = collect(DashboardMetrics::for([])['accountsToday'])->firstWhere('provider', 'worldpay');

    expect($row)->not->toBeNull()
        ->and($row['name'])->toBe('WP Main')
        ->and($row['accepted'])->toEqualCanonicalizing(['gbp' => 4200]);
});

it('filters the dashboard by the Worldpay provider and by one Worldpay account', function () {
    $account = WorldpayAccount::factory()->create();
    $other = WorldpayAccount::factory()->create();
    Payment::factory()->worldpay()->create(['worldpay_account_id' => $account->id, 'amount' => 1000, 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->worldpay()->create(['worldpay_account_id' => $other->id, 'amount' => 2000, 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->create(['amount' => 5000, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);

    $byProvider = collect(DashboardMetrics::for(['provider' => 'worldpay'])['accountsToday']);
    $byAccount = collect(DashboardMetrics::for(['account' => "worldpay:{$account->id}"])['accountsToday']);

    expect($byProvider->pluck('provider')->unique()->all())->toBe(['worldpay'])
        ->and($byProvider)->toHaveCount(2)
        ->and($byAccount)->toHaveCount(1)
        ->and($byAccount->first()['accepted'])->toEqualCanonicalizing(['gbp' => 1000]);
});

it('lists Worldpay accounts in the dashboard account filter and accepts the provider filter', function () {
    WorldpayAccount::factory()->create(['account_name' => 'WP Main']);

    $this->actingAs(wpParityAdmin())->get('/dashboard?provider=worldpay')
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('filterOptions.accounts', fn ($accounts) => collect($accounts)->contains(fn ($a) => $a['provider'] === 'worldpay' && $a['name'] === 'WP Main')));
});

it('filters the payments list by the Worldpay provider', function () {
    $worldpay = Payment::factory()->worldpay()->create();
    Payment::factory()->create();

    $this->actingAs(wpParityAdmin())->get('/payments?provider=worldpay')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.provider', 'worldpay')
            ->where('payments.data.0.uuid', $worldpay->uuid));
});

it('lets an admin open a Worldpay payment with its attempts and the edit form', function () {
    $payment = Payment::factory()->worldpay()->create(['worldpay_payment_id' => 'payXYZ']);
    $page = WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->addDay()]);
    WorldpayLookupAttempt::create(['payment_id' => $payment->id, 'page_id' => $page->id, 'source' => 'poll', 'outcome' => 'declined', 'last_event' => 'authorizationFailed', 'last_checked_at' => now()]);
    $admin = wpParityAdmin();

    $this->actingAs($admin)->get("/payments/{$payment->uuid}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('payment.provider', 'worldpay')
            ->where('payment.provider_label', 'Worldpay')
            ->where('payment.provider_reference', 'payXYZ')
            ->where('attempts.provider', 'worldpay')
            ->where('attempts.rows.0.status', 'declined')
            ->where('attempts.rows.0.status_detail', 'authorizationFailed')
            ->where('attempts.rows.0.source', 'poll')
            ->where('attempts.summary.page_count', 1));

    $this->actingAs($admin)->get("/payments/{$payment->uuid}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('payment.provider', 'worldpay')
            ->where('payment.account_id', $payment->worldpay_account_id)
            ->where('accounts', fn ($accounts) => collect($accounts)->contains(fn ($a) => $a['provider'] === 'worldpay' && $a['currency'] === 'gbp')));
});

it('shows the Worldpay account in the admin user account lists', function () {
    WorldpayAccount::factory()->create(['account_name' => 'WP Main']);

    $this->actingAs(wpParityAdmin())->get('/admin/users/create')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('accountsByCurrency', fn ($by) => collect($by)->flatten(1)->contains(fn ($a) => $a['provider'] === 'worldpay')));
});

it('counts Worldpay payments in the central summary', function () {
    config([
        'central.token_hash_current' => hash('sha256', 'wp-token'),
        'central.token_hash_next' => null,
        'central.require_https' => false,
    ]);
    Payment::factory()->worldpay()->create(['status' => 'completed', 'amount' => 1500, 'paid_at' => now()]);

    $this->getJson('/api/central/v1/summary', ['Authorization' => 'Bearer wp-token'])
        ->assertOk()
        ->assertJsonPath('windows.all_time.links.completed', 1)
        ->assertJsonPath('windows.all_time.money.completed.gbp', 1500);
});

it('never lets a Worldpay payment fall through to the Stripe pay page', function () {
    $payment = Payment::factory()->worldpay()->create();

    $this->get("/pay/{$payment->uuid}")->assertInertia(fn (Assert $p) => $p->component('ClientPayment/PayWorldpay'));
});
