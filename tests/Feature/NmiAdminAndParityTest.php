<?php

use App\Exports\PaymentsExport;
use App\Models\Brand;
use App\Models\NmiAccount;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

function nmiAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function validNmiAccountData(array $overrides = []): array
{
    return array_merge([
        'account_name' => 'Beyond Bancard Main',
        'prefix' => 'NMI',
        'environment' => 'sandbox',
        'currency' => 'usd',
        'private_key' => 'a_private_key_value_12345678901234',
        'public_tokenization_key' => 'abc123-DEF456-ghi789-JKL012',
        'webhook_signing_key' => 'signing-key-value',
        'three_ds_enabled' => true,
        'google_pay_enabled' => false,
    ], $overrides);
}

// ---- Admin account CRUD (AC-1, AC-2) ----

it('lets an admin create an NMI account with the keys encrypted and never sent back', function () {
    $this->actingAs(nmiAdmin())->post('/admin/nmi-accounts', validNmiAccountData())
        ->assertRedirect('/admin/nmi-accounts')->assertSessionHasNoErrors();

    $account = NmiAccount::firstOrFail();
    expect($account->private_key)->toBe('a_private_key_value_12345678901234')
        ->and($account->webhook_signing_key)->toBe('signing-key-value')
        ->and($account->getRawOriginal('private_key'))->not->toContain('a_private_key')
        ->and($account->getRawOriginal('webhook_signing_key'))->not->toContain('signing-key');

    $this->actingAs(nmiAdmin())->get("/admin/nmi-accounts/{$account->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nmiAccount.has_private_key', true)
            ->where('nmiAccount.has_webhook_signing_key', true)
            ->missing('nmiAccount.private_key')
            ->missing('nmiAccount.webhook_signing_key'));

    $response = $this->actingAs(nmiAdmin())->get('/admin/nmi-accounts');
    expect($response->getContent())->not->toContain('a_private_key_value')->not->toContain('signing-key-value');
});

it('keeps the stored keys when they are left blank on update', function () {
    $account = NmiAccount::factory()->create(['webhook_signing_key' => null]);
    $account->forceFill(['private_key' => 'old_private_key', 'webhook_signing_key' => 'old-signing'])->save();

    $this->actingAs(nmiAdmin())->put("/admin/nmi-accounts/{$account->id}", validNmiAccountData([
        'private_key' => '', 'webhook_signing_key' => '', 'public_tokenization_key' => $account->public_tokenization_key,
    ]))->assertSessionHasNoErrors();

    $account->refresh();
    expect($account->private_key)->toBe('old_private_key')->and($account->webhook_signing_key)->toBe('old-signing');
});

it('rejects a bad public key, a private key pasted as the public key, and a duplicate public key', function () {
    $this->actingAs(nmiAdmin())->post('/admin/nmi-accounts', validNmiAccountData(['public_tokenization_key' => 'nope']))
        ->assertSessionHasErrors('public_tokenization_key');

    $same = 'abc123-DEF456-ghi789-JKL012';
    $this->actingAs(nmiAdmin())->post('/admin/nmi-accounts', validNmiAccountData(['private_key' => $same, 'public_tokenization_key' => $same]))
        ->assertSessionHasErrors(['public_tokenization_key' => 'This looks like a private key. Paste the public tokenization key.']);

    $existing = NmiAccount::factory()->create();
    $this->actingAs(nmiAdmin())->post('/admin/nmi-accounts', validNmiAccountData(['public_tokenization_key' => $existing->public_tokenization_key]))
        ->assertSessionHasErrors('public_tokenization_key');
});

it('keeps an inactive account\'s public key reserved', function () {
    $existing = NmiAccount::factory()->create(['is_active' => false]);

    $this->actingAs(nmiAdmin())->post('/admin/nmi-accounts', validNmiAccountData(['public_tokenization_key' => $existing->public_tokenization_key]))
        ->assertSessionHasErrors('public_tokenization_key');
});

it('keeps NMI account admin away from agents and accounts', function () {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $this->actingAs($agent)->get('/admin/nmi-accounts')->assertForbidden();
    $this->actingAs($agent)->post('/admin/nmi-accounts', validNmiAccountData())->assertForbidden();
});

it('activates, deactivates and refuses to delete an account that has payments', function () {
    $account = NmiAccount::factory()->create();
    $admin = nmiAdmin();

    $this->actingAs($admin)->patch("/admin/nmi-accounts/{$account->id}/deactivate");
    expect($account->fresh()->is_active)->toBeFalse();
    $this->actingAs($admin)->patch("/admin/nmi-accounts/{$account->id}/activate");
    expect($account->fresh()->is_active)->toBeTrue();

    Payment::factory()->nmi()->create(['nmi_account_id' => $account->id]);
    $this->actingAs($admin)->delete("/admin/nmi-accounts/{$account->id}");
    expect(NmiAccount::find($account->id))->not->toBeNull();
});

it('tests the connection with one read only lookup and shows NMI\'s own error in plain words', function () {
    Http::fake(['sandbox.nmi.com/api/query.php' => Http::sequence()
        ->push('<nm_response></nm_response>')
        ->push('<nm_response><error_response>Specified API key not found REFID:1</error_response></nm_response>')]);
    $this->actingAs(nmiAdmin())->post('/admin/nmi-accounts/test-connection', ['private_key' => 'good_key', 'environment' => 'sandbox'])
        ->assertSessionHasNoErrors();
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'query.php') && ! str_contains($r->url(), 'transact') && $r['order_id'] !== null);

    $this->actingAs(nmiAdmin())->post('/admin/nmi-accounts/test-connection', ['private_key' => 'bad_key', 'environment' => 'sandbox'])
        ->assertSessionHasErrors(['nmi_api' => 'Specified API key not found']);
});

// ---- Payment requests (AC-3, AC-13) ----

it('rejects a payment whose currency differs from the NMI account', function () {
    $account = NmiAccount::factory()->create(['currency' => 'gbp']);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();

    $this->actingAs(nmiAdmin())->post('/payments', [
        'brand_id' => $brand->id, 'relationship_manager_id' => $rm->id, 'provider' => 'nmi', 'account_id' => $account->id,
        'currency' => 'usd', 'amount' => '20.00', 'client_name' => 'Alice',
    ])->assertSessionHasErrors(['currency' => 'This NMI account only accepts GBP payments.']);

    $this->actingAs(nmiAdmin())->post('/payments', [
        'brand_id' => $brand->id, 'relationship_manager_id' => $rm->id, 'provider' => 'nmi', 'account_id' => $account->id,
        'currency' => 'gbp', 'amount' => '20.00', 'client_name' => 'Alice',
    ])->assertSessionHasNoErrors();

    expect(Payment::firstOrFail()->nmi_account_id)->toBe($account->id);
});

it('never accepts PKR on NMI', function () {
    $account = NmiAccount::factory()->create();
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();

    $this->actingAs(nmiAdmin())->post('/payments', [
        'brand_id' => $brand->id, 'relationship_manager_id' => $rm->id, 'provider' => 'nmi', 'account_id' => $account->id,
        'currency' => 'pkr', 'amount' => '20.00', 'client_name' => 'Alice',
    ])->assertSessionHasErrors('currency');
});

it('locks amount, currency and account after the first charge attempt and blocks deletion', function () {
    $payment = Payment::factory()->nmi()->create(['amount' => 1000, 'currency' => 'usd', 'status' => 'pending', 'nmi_first_attempt_at' => now()]);
    $admin = nmiAdmin();

    $this->actingAs($admin)->patch("/payments/{$payment->uuid}", [
        'brand_id' => $payment->brand_id, 'relationship_manager_id' => $payment->relationship_manager_id,
        'provider' => 'nmi', 'account_id' => $payment->nmi_account_id,
        'currency' => 'usd', 'amount' => '99.00', 'client_name' => 'Alice',
    ])->assertSessionHasErrors('amount');

    $this->actingAs($admin)->delete("/payments/{$payment->uuid}");
    expect(Payment::find($payment->id))->not->toBeNull();
});

// ---- Everywhere the other nine providers appear (AC-4) ----

it('exports an NMI payment with its provider, account and transaction id', function () {
    $account = NmiAccount::factory()->create(['account_name' => 'NMI Main', 'prefix' => 'NM']);
    $payment = Payment::factory()->nmi()->create(['nmi_account_id' => $account->id, 'nmi_transaction_id' => 'tx_77', 'nmi_order_id' => (string) Str::uuid()]);

    $row = (new PaymentsExport(Payment::query()))->map($payment->load(['brand', 'nmiAccount', 'relationshipManager']));

    expect($row)->toContain('NMI')->toContain('NMI Main')->toContain('tx_77')->and($row[0])->toStartWith('NM-');
});

it('falls back to the order id in the export before a transaction exists', function () {
    $order = (string) Str::uuid();
    $payment = Payment::factory()->nmi()->create(['nmi_order_id' => $order, 'nmi_transaction_id' => null]);

    $row = (new PaymentsExport(Payment::query()))->map($payment->load(['brand', 'nmiAccount', 'relationshipManager']));

    expect($row)->toContain($order);
});

it('shows an NMI account in Accounts Today and filters the dashboard by provider and account', function () {
    $account = NmiAccount::factory()->create(['account_name' => 'NMI Main']);
    $other = NmiAccount::factory()->create();
    Payment::factory()->nmi()->create(['nmi_account_id' => $account->id, 'amount' => 4200, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->nmi()->create(['nmi_account_id' => $other->id, 'amount' => 1000, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->create(['amount' => 5000, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);

    $row = collect(DashboardMetrics::for([])['accountsToday'])->firstWhere('name', 'NMI Main');
    $byProvider = collect(DashboardMetrics::for(['provider' => 'nmi'])['accountsToday']);
    $byAccount = collect(DashboardMetrics::for(['account' => "nmi:{$account->id}"])['accountsToday']);

    expect($row['provider'])->toBe('nmi')
        ->and($row['accepted'])->toEqualCanonicalizing(['usd' => 4200])
        ->and($byProvider->pluck('provider')->unique()->all())->toBe(['nmi'])
        ->and($byAccount)->toHaveCount(1);

    $this->actingAs(nmiAdmin())->get('/dashboard?provider=nmi')->assertOk()->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('filterOptions.accounts', fn ($a) => collect($a)->contains(fn ($x) => $x['provider'] === 'nmi' && $x['name'] === 'NMI Main')));
});

it('filters the payments list and opens show and edit for an NMI payment', function () {
    $payment = Payment::factory()->nmi()->create(['nmi_transaction_id' => 'tx_88']);
    Payment::factory()->create();
    $admin = nmiAdmin();

    $this->actingAs($admin)->get('/payments?provider=nmi')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('payments.data', 1)->where('payments.data.0.provider', 'nmi'));

    $this->actingAs($admin)->get("/payments/{$payment->uuid}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payment.provider', 'nmi')
            ->where('payment.provider_label', 'NMI')
            ->where('payment.provider_reference', 'tx_88'));

    $this->actingAs($admin)->get("/payments/{$payment->uuid}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payment.account_id', $payment->nmi_account_id)
            ->where('accounts', fn ($a) => collect($a)->contains(fn ($x) => $x['provider'] === 'nmi' && $x['currency'] === 'usd')));
});

it('shows the attempts panel to admins with the attempt rows', function () {
    $payment = Payment::factory()->nmi()->create(['nmi_first_attempt_at' => now()]);
    $payment->nmiChargeAttempts()->create(['sequence' => 1, 'method' => 'card', 'status' => 'declined', 'reason' => 'Card declined by the bank', 'transaction_id' => '55']);

    $this->actingAs(nmiAdmin())->get("/payments/{$payment->uuid}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('attempts.provider', 'nmi')
            ->where('attempts.rows.0.status', 'declined')
            ->where('attempts.rows.0.reference', '55'));
});

it('counts NMI payments in the central summary', function () {
    config([
        'central.token_hash_current' => hash('sha256', 'nmi-token'),
        'central.token_hash_next' => null,
        'central.require_https' => false,
    ]);
    Payment::factory()->nmi()->create(['status' => 'completed', 'currency' => 'usd', 'amount' => 1500, 'paid_at' => now()]);

    $this->getJson('/api/central/v1/summary', ['Authorization' => 'Bearer nmi-token'])
        ->assertOk()
        ->assertJsonPath('windows.all_time.links.completed', 1)
        ->assertJsonPath('windows.all_time.money.completed.usd', 1500);
});

it('lets an agent with a USD NMI slot create an NMI payment without choosing an account', function () {
    $account = NmiAccount::factory()->create();
    $agent = User::factory()->create();
    $agent->assignRole('agent');
    $agent->paymentAccounts()->create(['currency' => 'usd', 'provider' => 'nmi', 'account_id' => $account->id]);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent->brands()->attach($brand);
    $agent->relationshipManagers()->attach($rm);

    $this->actingAs($agent)->post('/payments', [
        'brand_id' => $brand->id, 'relationship_manager_id' => $rm->id,
        'currency' => 'usd', 'amount' => '20.00', 'client_name' => 'Alice',
    ])->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('nmi')->and($payment->nmi_account_id)->toBe($account->id);
});

it('offers NMI accounts to the admin user form', function () {
    NmiAccount::factory()->create(['account_name' => 'NMI Main']);

    $this->actingAs(nmiAdmin())->get('/admin/users/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('accountsByCurrency.usd', fn ($a) => collect($a)->contains(fn ($x) => $x['provider'] === 'nmi' && $x['account_name'] === 'NMI Main')));
});
