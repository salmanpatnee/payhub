<?php

use App\Exports\PaymentsExport;
use App\Models\Brand;
use App\Models\MpgsAccount;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

function mpgsPaymentPayload(Brand $brand, MpgsAccount $account, string $currency = 'usd'): array
{
    return [
        'brand_id' => $brand->id,
        'provider' => 'mpgs',
        'account_id' => $account->id,
        'relationship_manager_id' => RelationshipManager::factory()->create()->id,
        'currency' => $currency,
        'amount' => '25.00',
        'client_name' => 'Alice Smith',
        'client_email' => 'alice@example.com',
        'service' => 'Web Design',
        'package' => 'standard',
        'note' => null,
    ];
}

it('lets an admin create an MPGS payment in USD or GBP (no currency lock)', function (string $currency) {
    $admin = User::factory()->create()->assignRole('admin');
    $account = MpgsAccount::factory()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->post('/payments', mpgsPaymentPayload(Brand::factory()->create(), $account, $currency))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('mpgs');
    expect($payment->mpgs_account_id)->toBe($account->id);
    expect($payment->stripe_account_id)->toBeNull();
    expect($payment->currency)->toBe($currency);
    expect($payment->amount)->toBe(2500);
})->with(['usd', 'gbp']);

it('rejects an MPGS payment against an inactive account', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = MpgsAccount::factory()->create(['is_active' => false]);

    $this->actingAs($admin)
        ->post('/payments', mpgsPaymentPayload(Brand::factory()->create(), $account))
        ->assertSessionHasErrors(['account_id']);

    expect(Payment::count())->toBe(0);
});

it('routes an agent to their assigned MPGS account by currency', function () {
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $account = MpgsAccount::factory()->create(['is_active' => true]);
    $agent = User::factory()->create()->assignRole('agent');
    $agent->brands()->attach($brand);
    $agent->relationshipManagers()->attach($rm);
    $agent->paymentAccounts()->create(['currency' => 'gbp', 'provider' => 'mpgs', 'account_id' => $account->id]);

    $this->actingAs($agent)
        ->post('/payments', [
            'brand_id' => $brand->id,
            'relationship_manager_id' => $rm->id,
            'currency' => 'gbp',
            'amount' => '10.00',
            'client_name' => 'Bob',
        ])
        ->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('mpgs');
    expect($payment->mpgs_account_id)->toBe($account->id);
});

it('lets an admin assign an MPGS account to an agent', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = MpgsAccount::factory()->create(['is_active' => true]);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'MPGS Agent',
            'username' => 'mpgsagent',
            'password' => 'Password1!',
            'role' => 'agent',
            'payment_accounts' => [['currency' => 'usd', 'provider' => 'mpgs', 'account_id' => $account->id]],
            'brand_ids' => [$brand->id],
            'relationship_manager_ids' => [$rm->id],
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('user_payment_accounts', [
        'currency' => 'usd', 'provider' => 'mpgs', 'account_id' => $account->id,
    ]);
});

it('clears a stale provider reference when an edit moves a payment off MPGS', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $mpgs = MpgsAccount::factory()->create(['is_active' => true]);
    $stripe = StripeAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();
    $payment = Payment::factory()->mpgs()->create([
        'user_id' => $admin->id,
        'status' => 'pending',
        'relationship_manager_id' => $rm->id,
        'mpgs_account_id' => $mpgs->id,
        'mpgs_order_id' => '1-1',
        'currency' => 'usd',
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", [
            'brand_id' => $payment->brand_id,
            'provider' => 'stripe',
            'account_id' => $stripe->id,
            'relationship_manager_id' => $payment->relationship_manager_id,
            'currency' => 'usd',
            'amount' => '25.00',
            'client_name' => $payment->client_name,
        ])
        ->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->provider->value)->toBe('stripe');
    expect($payment->mpgs_account_id)->toBeNull();
    expect($payment->mpgs_order_id)->toBeNull();
});

it('lets an admin move a payment onto an MPGS account', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $mpgs = MpgsAccount::factory()->create(['is_active' => true]);
    $payment = Payment::factory()->create(['user_id' => $admin->id, 'status' => 'pending', 'stripe_payment_intent_id' => 'pi_old']);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", mpgsPaymentPayload(Brand::factory()->create(), $mpgs))
        ->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->provider->value)->toBe('mpgs');
    expect($payment->mpgs_account_id)->toBe($mpgs->id);
    expect($payment->stripe_account_id)->toBeNull();
    expect($payment->stripe_payment_intent_id)->toBeNull();
});

it('shows an MPGS payment on the show page with its order id as the provider reference', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $payment = Payment::factory()->mpgs()->create(['mpgs_order_id' => '42-1', 'status' => 'completed']);

    $this->actingAs($admin)
        ->get(route('payments.show', $payment))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payment.provider', 'mpgs')
            ->where('payment.provider_label', 'MPGS')
            ->where('payment.provider_reference', '42-1')
        );
});

it('lists MPGS payments on the index page', function () {
    $admin = User::factory()->create()->assignRole('admin');
    Payment::factory()->mpgs()->create();

    $this->actingAs($admin)->get(route('payments.index'))->assertOk();
    $this->actingAs($admin)->get(route('payments.index', ['provider' => 'mpgs']))->assertOk();
});

it('maps provider, account name and order id for MPGS rows in the export', function () {
    $account = MpgsAccount::factory()->create(['account_name' => 'Acme MPGS']);
    $payment = Payment::factory()->mpgs()->create([
        'mpgs_account_id' => $account->id,
        'mpgs_order_id' => '7-2',
    ]);

    $row = (new PaymentsExport(Payment::query()))->map($payment->load('mpgsAccount', 'brand', 'relationshipManager'));

    expect($row[6])->toBe('MPGS');
    expect($row[7])->toBe('Acme MPGS');
    expect($row[8])->toBe('7-2');
});

it('includes MPGS accounts in the dashboard accounts-today panel and provider filter', function () {
    $account = MpgsAccount::factory()->create(['account_name' => 'MPGS Main']);
    $other = MpgsAccount::factory()->create();

    Payment::factory()->mpgs()->create([
        'mpgs_account_id' => $account->id,
        'amount' => 4200,
        'currency' => 'usd',
        'status' => 'completed',
        'paid_at' => now(),
    ]);
    Payment::factory()->mpgs()->create([
        'mpgs_account_id' => $other->id,
        'amount' => 900,
        'currency' => 'usd',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $row = collect(DashboardMetrics::for([])['accountsToday'])->firstWhere('name', 'MPGS Main');
    expect($row)->not->toBeNull();
    expect($row['provider'])->toBe('mpgs');
    expect($row['accepted'])->toEqualCanonicalizing(['usd' => 4200]);

    expect(DashboardMetrics::for(['provider' => 'mpgs'])['kpis']['collected'])->toBe(['usd' => 5100]);
    expect(DashboardMetrics::for(['account' => "mpgs:{$account->id}"])['kpis']['collected'])->toBe(['usd' => 4200]);
});

it('accepts provider=mpgs on the dashboard route and lists the account option', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = MpgsAccount::factory()->create();

    $this->actingAs($admin)
        ->get(route('dashboard', ['provider' => 'mpgs']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filterOptions.accounts', fn ($accounts) => collect($accounts)->contains('value', "mpgs:{$account->id}"))
        );
});

it('renders the MPGS pay page without the session id or any secret', function () {
    $payment = Payment::factory()->mpgs()->create();

    $this->get(route('pay.show', $payment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ClientPayment/PayMpgs')
            ->has('mpgsAccount', fn ($account) => $account
                ->has('gateway_host')
                ->has('merchant_id')
                ->has('api_version')
                ->missing('integration_password')
                ->missing('webhook_secret'))
            ->missing('session_id'));
});
