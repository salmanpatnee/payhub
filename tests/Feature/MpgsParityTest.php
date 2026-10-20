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

it('lets an admin create a PKR payment on an MPGS account that accepts PKR', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = MpgsAccount::factory()->supportsPkr()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->post('/payments', mpgsPaymentPayload(Brand::factory()->create(), $account, 'pkr'))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $payment = Payment::firstOrFail();
    expect($payment->provider->value)->toBe('mpgs');
    expect($payment->currency)->toBe('pkr');
    expect($payment->amount)->toBe(2500);
});

it('rejects a PKR payment on an MPGS account that does not accept PKR', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = MpgsAccount::factory()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->post('/payments', mpgsPaymentPayload(Brand::factory()->create(), $account, 'pkr'))
        ->assertSessionHasErrors(['currency']);

    expect(Payment::count())->toBe(0);
});

it('rejects a PKR payment on a non-MPGS provider', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $stripe = StripeAccount::factory()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->post('/payments', array_merge(
            mpgsPaymentPayload(Brand::factory()->create(), MpgsAccount::factory()->supportsPkr()->create(), 'pkr'),
            ['provider' => 'stripe', 'account_id' => $stripe->id],
        ))
        ->assertSessionHasErrors(['currency']);

    expect(Payment::count())->toBe(0);
});

it('does not let an agent without a PKR slot create a PKR payment', function () {
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $account = MpgsAccount::factory()->supportsPkr()->create(['is_active' => true]);
    $agent = User::factory()->create()->assignRole('agent');
    $agent->brands()->attach($brand);
    $agent->relationshipManagers()->attach($rm);
    $agent->paymentAccounts()->create(['currency' => 'usd', 'provider' => 'mpgs', 'account_id' => $account->id]);

    $this->actingAs($agent)
        ->post('/payments', [
            'brand_id' => $brand->id,
            'relationship_manager_id' => $rm->id,
            'currency' => 'pkr',
            'amount' => '10.00',
            'client_name' => 'Bob',
        ])
        ->assertSessionHasErrors(['currency']);

    expect(Payment::count())->toBe(0);
});

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

// ── Agent PKR slot: an admin "allows" PKR for an agent by assigning a PKR-enabled MPGS account ──

function mpgsPkrAgent(MpgsAccount $account, bool $withPkrSlot = true): array
{
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent = User::factory()->create()->assignRole('agent');
    $agent->brands()->attach($brand);
    $agent->relationshipManagers()->attach($rm);

    if ($withPkrSlot) {
        $agent->paymentAccounts()->create(['currency' => 'pkr', 'provider' => 'mpgs', 'account_id' => $account->id]);
    }

    return [$agent, $brand, $rm];
}

it('lets an agent with a PKR slot create a PKR payment routed to that MPGS account', function () {
    $account = MpgsAccount::factory()->supportsPkr()->create(['is_active' => true]);
    [$agent, $brand, $rm] = mpgsPkrAgent($account);

    $this->actingAs($agent)
        ->post('/payments', [
            'brand_id' => $brand->id,
            'relationship_manager_id' => $rm->id,
            'currency' => 'pkr',
            'amount' => '2500.00',
            'client_name' => 'Bob',
        ])
        ->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    expect($payment->currency)->toBe('pkr')
        ->and($payment->provider->value)->toBe('mpgs')
        ->and($payment->mpgs_account_id)->toBe($account->id)
        ->and($payment->amount)->toBe(250000);
});

it('rejects a PKR payment from an agent whose PKR account has been deactivated', function () {
    $account = MpgsAccount::factory()->supportsPkr()->create(['is_active' => true]);
    [$agent, $brand, $rm] = mpgsPkrAgent($account);
    $account->update(['is_active' => false]);

    $this->actingAs($agent)
        ->post('/payments', [
            'brand_id' => $brand->id,
            'relationship_manager_id' => $rm->id,
            'currency' => 'pkr',
            'amount' => '10.00',
            'client_name' => 'Bob',
        ])
        ->assertSessionHasErrors(['currency']);

    expect(Payment::count())->toBe(0);
});

it('offers PKR on the agent create form only when the agent has a PKR slot', function () {
    $account = MpgsAccount::factory()->supportsPkr()->create(['is_active' => true]);
    [$withSlot] = mpgsPkrAgent($account);
    [$withoutSlot] = mpgsPkrAgent($account, withPkrSlot: false);
    // An agent with no account at all is redirected away from the form, so give this one USD.
    $withoutSlot->paymentAccounts()->create(['currency' => 'usd', 'provider' => 'mpgs', 'account_id' => $account->id]);

    $currencies = function (User $agent) {
        $page = $this->actingAs($agent)->get(route('payments.create'))->assertOk()->original->getData()['page'];

        return collect($page['props']['agentCurrencies'])->pluck('currency')->all();
    };

    expect($currencies($withSlot))->toContain('pkr')
        ->and($currencies($withoutSlot))->not->toContain('pkr');
});

it('lets an admin give an agent a PKR slot on a PKR-enabled MPGS account', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = MpgsAccount::factory()->supportsPkr()->create(['is_active' => true]);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'PKR Agent',
            'username' => 'pkragent',
            'password' => 'Password1!',
            'role' => 'agent',
            'payment_accounts' => [['currency' => 'pkr', 'provider' => 'mpgs', 'account_id' => $account->id]],
            'brand_ids' => [$brand->id],
            'relationship_manager_ids' => [$rm->id],
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('user_payment_accounts', [
        'currency' => 'pkr', 'provider' => 'mpgs', 'account_id' => $account->id,
    ]);
});

it('refuses a PKR slot on an MPGS account without PKR, or on another provider', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $noPkr = MpgsAccount::factory()->create(['is_active' => true]);
    $stripe = StripeAccount::factory()->create(['is_active' => true]);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();

    foreach ([['mpgs', $noPkr->id], ['stripe', $stripe->id]] as $i => [$provider, $id]) {
        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Bad PKR',
                'username' => "badpkr{$i}",
                'password' => 'Password1!',
                'role' => 'agent',
                'payment_accounts' => [['currency' => 'pkr', 'provider' => $provider, 'account_id' => $id]],
                'brand_ids' => [$brand->id],
                'relationship_manager_ids' => [$rm->id],
            ])
            ->assertSessionHasErrors(['payment_accounts.0.currency']);
    }

    $this->assertDatabaseMissing('user_payment_accounts', ['currency' => 'pkr']);
});

it('offers only PKR-enabled MPGS accounts in the admin PKR slot options', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $yes = MpgsAccount::factory()->supportsPkr()->create(['is_active' => true]);
    MpgsAccount::factory()->create(['is_active' => true]);
    StripeAccount::factory()->create(['is_active' => true]);

    $props = $this->actingAs($admin)->get(route('admin.users.create'))->viewData('page')['props'];

    expect($props['accountsByCurrency']['pkr'])->toHaveCount(1)
        ->and($props['accountsByCurrency']['pkr'][0]['id'])->toBe($yes->id)
        ->and($props['accountsByCurrency']['pkr'][0]['provider'])->toBe('mpgs');
});

it('lets an admin add a PKR slot to an agent whose existing USD account has since been deactivated', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $oldStripe = StripeAccount::factory()->create(['is_active' => false]);
    $pkr = MpgsAccount::factory()->supportsPkr()->create(['is_active' => true]);
    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent = User::factory()->create()->assignRole('agent');
    $agent->brands()->attach($brand);
    $agent->relationshipManagers()->attach($rm);
    $agent->paymentAccounts()->create(['currency' => 'usd', 'provider' => 'stripe', 'account_id' => $oldStripe->id]);

    // The edit form re-sends the unchanged (now inactive) USD slot together with the new PKR one.
    $payload = [
        'name' => $agent->name,
        'username' => $agent->username,
        'role' => 'agent',
        'payment_accounts' => [
            ['currency' => 'usd', 'provider' => 'stripe', 'account_id' => (string) $oldStripe->id],
            ['currency' => 'pkr', 'provider' => 'mpgs', 'account_id' => (string) $pkr->id],
        ],
        'brand_ids' => [$brand->id],
        'relationship_manager_ids' => [$rm->id],
    ];

    $this->actingAs($admin)->patch(route('admin.users.update', $agent), $payload)->assertSessionHasNoErrors();

    $this->assertDatabaseHas('user_payment_accounts', ['user_id' => $agent->id, 'currency' => 'pkr', 'account_id' => $pkr->id]);

    // An inactive account is still refused when it is newly assigned.
    $other = User::factory()->create()->assignRole('agent');
    $other->brands()->attach($brand);
    $other->relationshipManagers()->attach($rm);
    $payload['username'] = $other->username;
    $payload['name'] = $other->name;

    $this->actingAs($admin)->patch(route('admin.users.update', $other), $payload)
        ->assertSessionHasErrors(['payment_accounts.0.account_id']);
});
