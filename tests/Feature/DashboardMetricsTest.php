<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\SquareAccount;
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
    Role::firstOrCreate(['name' => 'account', 'guard_name' => 'web']);
});

/**
 * Seed a fixed payment set:
 *  - completed: usd 10000 (brandA/rm1), usd 5000 (brandB/rm2), gbp 8000 (brandA/rm1)
 *  - pending:   usd 7000 (brandA)
 *  - failed:    usd 3000 (brandB)
 *
 * @return array{brandA: Brand, brandB: Brand, rm1: RelationshipManager}
 */
function seedDashboardPayments(): array
{
    $brandA = Brand::factory()->create(['name' => 'Brand A']);
    $brandB = Brand::factory()->create(['name' => 'Brand B']);
    $account = StripeAccount::factory()->create();
    $rm1 = RelationshipManager::factory()->create(['name' => 'RM One']);
    $rm2 = RelationshipManager::factory()->create(['name' => 'RM Two']);

    $base = ['stripe_account_id' => $account->id];

    Payment::factory()->for($brandA)->create([...$base, 'relationship_manager_id' => $rm1->id, 'amount' => 10000, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->for($brandB)->create([...$base, 'relationship_manager_id' => $rm2->id, 'amount' => 5000, 'currency' => 'usd', 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->for($brandA)->create([...$base, 'relationship_manager_id' => $rm1->id, 'amount' => 8000, 'currency' => 'gbp', 'status' => 'completed', 'paid_at' => now()]);
    Payment::factory()->for($brandA)->create([...$base, 'amount' => 7000, 'currency' => 'usd', 'status' => 'pending']);
    Payment::factory()->for($brandB)->create([...$base, 'amount' => 3000, 'currency' => 'usd', 'status' => 'failed']);

    return ['brandA' => $brandA, 'brandB' => $brandB, 'rm1' => $rm1];
}

it('computes collected revenue per currency without merging', function () {
    seedDashboardPayments();

    $kpis = DashboardMetrics::for([])['kpis'];

    expect($kpis['collected'])->toEqualCanonicalizing(['usd' => 15000, 'gbp' => 8000]);
    expect($kpis['collected'])->toHaveKeys(['usd', 'gbp']);
});

it('computes conversion and success rates', function () {
    seedDashboardPayments();

    $kpis = DashboardMetrics::for([])['kpis'];

    // 3 completed of 5 total = 60%
    expect($kpis['conversionRate'])->toBe(60.0);
    // 3 completed of (3 completed + 1 failed) = 75%
    expect($kpis['successRate'])->toBe(75.0);
});

it('reports pending pipeline amount and count', function () {
    seedDashboardPayments();

    $kpis = DashboardMetrics::for([])['kpis'];

    expect($kpis['pendingPipeline']['amounts'])->toBe(['usd' => 7000]);
    expect($kpis['pendingPipeline']['count'])->toBe(1);
    expect($kpis['activeBrands'])->toBe(2);
});

it('ranks brand performance by completed revenue', function () {
    seedDashboardPayments();

    $brands = DashboardMetrics::for([])['brandPerformance'];

    expect($brands[0]['name'])->toBe('Brand A');
    expect($brands[0]['revenue'])->toEqualCanonicalizing(['usd' => 10000, 'gbp' => 8000]);
    expect($brands[1]['name'])->toBe('Brand B');
    expect($brands[1]['revenue'])->toBe(['usd' => 5000]);
});

it('ranks rm leaderboard by completed revenue', function () {
    seedDashboardPayments();

    $rms = DashboardMetrics::for([])['rmLeaderboard'];

    expect($rms[0]['name'])->toBe('RM One');
    expect($rms[0]['revenue'])->toEqualCanonicalizing(['usd' => 10000, 'gbp' => 8000]);
});

it('builds a daily revenue trend per currency', function () {
    seedDashboardPayments();

    $trend = DashboardMetrics::for([])['revenueTrend'];

    expect($trend)->not->toBeEmpty();
    $usd = collect($trend)->where('currency', 'usd')->sum('total');
    $gbp = collect($trend)->where('currency', 'gbp')->sum('total');
    expect($usd)->toBe(15000);
    expect($gbp)->toBe(8000);
});

it('excludes revenue trend points paid outside the selected range even if created inside it', function () {
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create();

    Payment::factory()->for($brand)->create([
        'stripe_account_id' => $account->id,
        'amount' => 5000,
        'currency' => 'usd',
        'status' => 'completed',
        'created_at' => now()->subDays(10),
        'paid_at' => now()->subDays(10),
    ]);

    $trend = DashboardMetrics::for(['from' => now()->toDateString()])['revenueTrend'];

    expect($trend)->toBeEmpty();
});

it('defaults the dashboard to an all-time window when no filters are applied', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.from', null)
            ->where('filters.to', null)
        );
});

it('narrows results when a currency filter is applied', function () {
    seedDashboardPayments();

    $kpis = DashboardMetrics::for(['currency' => 'usd'])['kpis'];

    expect($kpis['collected'])->toBe(['usd' => 15000]);
    expect($kpis['collected'])->not->toHaveKey('gbp');
});

it('narrows results when a brand filter is applied', function () {
    $ids = seedDashboardPayments();

    $kpis = DashboardMetrics::for(['brand_id' => $ids['brandB']->id])['kpis'];

    expect($kpis['collected'])->toBe(['usd' => 5000]);
});

it('surfaces the high-value payment in the worklist', function () {
    seedDashboardPayments();

    $worklist = DashboardMetrics::for([])['worklist'];

    expect($worklist['highValue'][0]['amount'])->toBe(10000);
    expect($worklist['stalePending'][0]['status'])->toBe('pending');
});

it('summarises accepted and pending per account for today', function () {
    seedDashboardPayments();

    $accounts = DashboardMetrics::for([])['accountsToday'];

    expect($accounts)->toHaveCount(1);
    expect($accounts[0]['accepted'])->toEqualCanonicalizing(['usd' => 15000, 'gbp' => 8000]);
    expect($accounts[0]['pending'])->toEqualCanonicalizing(['usd' => 7000]);
});

it('respects the brand filter for the accounts-today panel', function () {
    $ids = seedDashboardPayments();

    $accounts = DashboardMetrics::for(['brand_id' => $ids['brandB']->id])['accountsToday'];

    expect($accounts)->toHaveCount(1);
    expect($accounts[0]['accepted'])->toEqualCanonicalizing(['usd' => 5000]);
    expect($accounts[0]['pending'])->toBe([]);
});

it('respects the date-range filter for the accounts-today panel', function () {
    seedDashboardPayments();

    $accounts = DashboardMetrics::for(['from' => '2020-01-01', 'to' => '2020-01-02'])['accountsToday'];

    expect($accounts)->toBe([]);
});

it('excludes pending links created outside the selected range', function () {
    $ids = seedDashboardPayments();

    Payment::factory()->for($ids['brandA'])->create([
        'amount' => 9999,
        'currency' => 'usd',
        'status' => 'pending',
        'created_at' => now()->subDays(3),
    ]);

    $accounts = DashboardMetrics::for(['from' => now()->toDateString()])['accountsToday'];

    expect($accounts[0]['pending'])->toEqualCanonicalizing(['usd' => 7000]);
});

it('defaults the accounts-today panel to today (not all-time like the rest of the dashboard) when no range is applied', function () {
    $ids = seedDashboardPayments();
    $account = StripeAccount::query()->first();

    Payment::factory()->for($ids['brandA'])->create([
        'stripe_account_id' => $account->id,
        'amount' => 9999,
        'currency' => 'usd',
        'status' => 'pending',
        'created_at' => now()->subDays(3),
    ]);

    $accounts = DashboardMetrics::for([])['accountsToday'];

    // Stale 3-day-old pending link is excluded — the panel's default is "today", unlike KPIs/trend/etc.
    expect($accounts[0]['pending'])->toEqualCanonicalizing(['usd' => 7000]);
});

it('lets an explicit all-time-ish range override the accounts-today panel default of today', function () {
    $ids = seedDashboardPayments();
    $account = StripeAccount::query()->first();

    Payment::factory()->for($ids['brandA'])->create([
        'stripe_account_id' => $account->id,
        'amount' => 9999,
        'currency' => 'usd',
        'status' => 'pending',
        'created_at' => now()->subDays(3),
    ]);

    $accounts = DashboardMetrics::for(['from' => now()->subDays(5)->toDateString()])['accountsToday'];

    expect($accounts[0]['pending'])->toEqualCanonicalizing(['usd' => 16999]);
});

it('includes square accounts in the accounts-today panel', function () {
    seedDashboardPayments();
    $square = SquareAccount::factory()->create(['account_name' => 'Square Main']);

    Payment::factory()->square()->create([
        'square_account_id' => $square->id,
        'amount' => 4200,
        'currency' => 'usd',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $accounts = collect(DashboardMetrics::for([])['accountsToday']);
    $squareRow = $accounts->firstWhere('provider', 'square');

    expect($squareRow)->not->toBeNull();
    expect($squareRow['name'])->toBe('Square Main');
    expect($squareRow['accepted'])->toEqualCanonicalizing(['usd' => 4200]);
});

it('accepts a square provider filter without error', function () {
    seedDashboardPayments();
    $square = SquareAccount::factory()->create();

    Payment::factory()->square()->create([
        'square_account_id' => $square->id,
        'amount' => 1500,
        'currency' => 'usd',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $kpis = DashboardMetrics::for(['provider' => 'square'])['kpis'];

    expect($kpis['collected'])->toBe(['usd' => 1500]);
});

it('passes metrics props to the dashboard page', function () {
    seedDashboardPayments();
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->has('kpis')
            ->has('revenueTrend')
            ->has('brandPerformance')
            ->has('accountsToday')
            ->has('filterOptions.brands')
        );
});

it('accepts provider=square on the dashboard route without a validation error', function () {
    seedDashboardPayments();
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->get(route('dashboard', ['provider' => 'square']))
        ->assertOk();
});

it('excludes inactive RMs from the dashboard filter dropdown by default', function () {
    seedDashboardPayments();
    $inactiveRm = RelationshipManager::factory()->inactive()->create(['name' => 'Retired RM']);
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filterOptions.relationshipManagers', fn ($rms) => collect($rms)->doesntContain('id', $inactiveRm->id))
        );
});

it('keeps the currently-filtered inactive RM visible in the dashboard filter dropdown', function () {
    seedDashboardPayments();
    $inactiveRm = RelationshipManager::factory()->inactive()->create(['name' => 'Retired RM']);
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->get(route('dashboard', ['relationship_manager_id' => $inactiveRm->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filterOptions.relationshipManagers', fn ($rms) => collect($rms)->contains('id', $inactiveRm->id))
        );
});

/**
 * Create a payment with explicit created/paid dates for the "received in this period" tests.
 */
function receivedPayment(Brand $brand, array $attrs): Payment
{
    return Payment::factory()->for($brand)->create([
        'stripe_account_id' => StripeAccount::factory()->create()->id,
        'currency' => 'usd',
        ...$attrs,
    ]);
}

const RECEIVED_RANGE = ['from' => '2026-03-10', 'to' => '2026-03-20'];

it('counts by created day when received is off, by paid day when on (AC-2, AC-3)', function () {
    $brand = Brand::factory()->create();
    receivedPayment($brand, ['amount' => 1000, 'status' => 'completed', 'created_at' => '2026-03-15 10:00:00', 'paid_at' => '2026-03-25 10:00:00']);
    receivedPayment($brand, ['amount' => 2000, 'status' => 'completed', 'created_at' => '2026-03-01 10:00:00', 'paid_at' => '2026-03-12 10:00:00']);

    $off = DashboardMetrics::for(RECEIVED_RANGE)['kpis'];
    $on = DashboardMetrics::for([...RECEIVED_RANGE, 'received' => true])['kpis'];

    expect($off['collected'])->toBe(['usd' => 1000]);
    expect($on['collected'])->toBe(['usd' => 2000]);
    expect($on['avgPaymentValue'])->toBe(['usd' => 2000]);
    expect($on['activeBrands'])->toBe(1);
});

it('counts a completed payment with no paid_at by created day when received is on (AC-5)', function () {
    $brand = Brand::factory()->create();
    receivedPayment($brand, ['amount' => 1500, 'status' => 'completed', 'created_at' => '2026-03-15 10:00:00', 'paid_at' => null]);

    $on = DashboardMetrics::for([...RECEIVED_RANGE, 'received' => true])['kpis'];

    expect($on['collected'])->toBe(['usd' => 1500]);
});

it('keeps pending, funnel and rates on created day (AC-6)', function () {
    $brand = Brand::factory()->create();
    receivedPayment($brand, ['amount' => 700, 'status' => 'pending', 'created_at' => '2026-03-15 10:00:00']);
    receivedPayment($brand, ['amount' => 1000, 'status' => 'completed', 'created_at' => '2026-03-15 10:00:00', 'paid_at' => '2026-03-25 10:00:00']);

    $off = DashboardMetrics::for(RECEIVED_RANGE);
    $on = DashboardMetrics::for([...RECEIVED_RANGE, 'received' => true]);

    expect($on['kpis']['pendingPipeline'])->toBe($off['kpis']['pendingPipeline']);
    expect($on['kpis']['conversionRate'])->toBe($off['kpis']['conversionRate']);
    expect($on['kpis']['successRate'])->toBe($off['kpis']['successRate']);
    expect($on['funnel'])->toBe($off['funnel']);
    expect($on['worklist'])->toBe($off['worklist']);
});

it('uses paid day for brand revenue but created day for conversion (AC-4)', function () {
    $brand = Brand::factory()->create(['name' => 'Brand X']);
    receivedPayment($brand, ['amount' => 1000, 'status' => 'completed', 'created_at' => '2026-03-15 10:00:00', 'paid_at' => '2026-03-25 10:00:00']);
    receivedPayment($brand, ['amount' => 500, 'status' => 'pending', 'created_at' => '2026-03-16 10:00:00']);
    receivedPayment($brand, ['amount' => 4000, 'status' => 'completed', 'created_at' => '2026-03-01 10:00:00', 'paid_at' => '2026-03-12 10:00:00']);

    $row = DashboardMetrics::for([...RECEIVED_RANGE, 'received' => true])['brandPerformance'][0];

    expect($row['revenue'])->toBe(['usd' => 4000]);
    expect($row['completedCount'])->toBe(1);
    expect($row['totalCount'])->toBe(2);
    expect($row['conversionRate'])->toBe(50.0);
});

it('lists a row that has only paid day money with null conversion (AC-4)', function () {
    $brand = Brand::factory()->create(['name' => 'Late Brand']);
    receivedPayment($brand, ['amount' => 4000, 'status' => 'completed', 'created_at' => '2026-03-01 10:00:00', 'paid_at' => '2026-03-12 10:00:00']);

    $rows = DashboardMetrics::for([...RECEIVED_RANGE, 'received' => true])['brandPerformance'];

    expect($rows)->toHaveCount(1);
    expect($rows[0]['revenue'])->toBe(['usd' => 4000]);
    expect($rows[0]['totalCount'])->toBe(0);
    expect($rows[0]['conversionRate'])->toBeNull();
});

it('sorts brands by paid day revenue when received is on (AC-4)', function () {
    $small = Brand::factory()->create(['name' => 'Small']);
    $big = Brand::factory()->create(['name' => 'Big']);
    receivedPayment($small, ['amount' => 9000, 'status' => 'completed', 'created_at' => '2026-03-15 10:00:00', 'paid_at' => '2026-03-25 10:00:00']);
    receivedPayment($small, ['amount' => 100, 'status' => 'completed', 'created_at' => '2026-03-15 10:00:00', 'paid_at' => '2026-03-15 10:00:00']);
    receivedPayment($big, ['amount' => 5000, 'status' => 'completed', 'created_at' => '2026-03-01 10:00:00', 'paid_at' => '2026-03-12 10:00:00']);

    $rows = DashboardMetrics::for([...RECEIVED_RANGE, 'received' => true])['brandPerformance'];

    expect($rows[0]['name'])->toBe('Big');
});

it('ignores received when no dates are set (AC-8)', function () {
    seedDashboardPayments();

    $off = DashboardMetrics::for([]);
    $on = DashboardMetrics::for(['received' => true]);

    expect($on['kpis'])->toBe($off['kpis']);
    expect($on['brandPerformance'])->toBe($off['brandPerformance']);
});

it('treats the paid day boundary correctly and supports open ended ranges (AC-3, AC-8)', function () {
    $brand = Brand::factory()->create();
    receivedPayment($brand, ['amount' => 1000, 'status' => 'completed', 'created_at' => '2026-03-01 10:00:00', 'paid_at' => '2026-03-20 23:59:00']);
    receivedPayment($brand, ['amount' => 2000, 'status' => 'completed', 'created_at' => '2026-03-01 10:00:00', 'paid_at' => '2026-03-21 00:00:00']);

    expect(DashboardMetrics::for([...RECEIVED_RANGE, 'received' => true])['kpis']['collected'])->toBe(['usd' => 1000]);
    expect(DashboardMetrics::for(['from' => '2026-03-21', 'received' => true])['kpis']['collected'])->toBe(['usd' => 2000]);
    expect(DashboardMetrics::for(['to' => '2026-03-20', 'received' => true])['kpis']['collected'])->toBe(['usd' => 1000]);
});

it('leaves revenue trend and accounts today the same on and off (AC-7)', function () {
    $brand = Brand::factory()->create();
    receivedPayment($brand, ['amount' => 1000, 'status' => 'completed', 'created_at' => '2026-03-15 10:00:00', 'paid_at' => '2026-03-25 10:00:00']);
    receivedPayment($brand, ['amount' => 2000, 'status' => 'completed', 'created_at' => '2026-03-01 10:00:00', 'paid_at' => '2026-03-12 10:00:00']);

    $off = DashboardMetrics::for(RECEIVED_RANGE);
    $on = DashboardMetrics::for([...RECEIVED_RANGE, 'received' => true]);

    expect($on['revenueTrend'])->toBe($off['revenueTrend']);
    expect($on['accountsToday'])->toBe($off['accountsToday']);
});

it('reads received from the query string as a safe boolean (AC-1, AC-9)', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    foreach ([['1', true], ['0', false], ['', false], ['junk', false]] as [$value, $expected]) {
        $this->actingAs($admin)
            ->get(route('dashboard', ['received' => $value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.received', $expected));
    }

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.received', false));
});
