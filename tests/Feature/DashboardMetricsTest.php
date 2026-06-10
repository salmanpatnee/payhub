<?php

use App\Models\Brand;
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
            ->has('filterOptions.brands')
        );
});
