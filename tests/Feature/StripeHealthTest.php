<?php

use App\Enums\StripeHealthStatus;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\StripeAccountHealth;
use App\Models\User;
use App\Services\Stripe\StripeAccountHealthChecker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Stripe\Account;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    foreach (['admin', 'agent', 'account'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function healthUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->syncRoles([$role]);

    return $user;
}

/**
 * Fake Stripe. $responses is a list of Account payload arrays or Throwables,
 * consumed one per retrieve() call (in account order).
 */
function fakeStripeAccounts(array $responses): void
{
    $accounts = Mockery::mock();
    $accounts->shouldReceive('retrieve')->andReturnUsing(function () use (&$responses) {
        $next = array_shift($responses);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return Account::constructFrom($next);
    });

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->accounts = $accounts;

    app()->bind(StripeClient::class, fn () => $stripe);
}

function healthyPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'acct_123',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        'country' => 'GB',
        'default_currency' => 'gbp',
        'requirements' => [
            'currently_due' => [], 'past_due' => [], 'eventually_due' => [],
            'pending_verification' => [], 'disabled_reason' => null, 'current_deadline' => null,
        ],
    ], $overrides);
}

// AC-1, AC-2
it('saves a healthy row for every account including inactive ones', function () {
    StripeAccount::factory()->create();
    StripeAccount::factory()->create(['is_active' => false]);
    fakeStripeAccounts([healthyPayload(), healthyPayload()]);

    $this->artisan('stripe:check-health')->assertSuccessful();

    expect(StripeAccountHealth::count())->toBe(2)
        ->and(StripeAccountHealth::where('status', 'healthy')->count())->toBe(2);
});

it('checks only the given account id and fails for an unknown id', function () {
    $a = StripeAccount::factory()->create();
    StripeAccount::factory()->create();
    fakeStripeAccounts([healthyPayload()]);

    $this->artisan('stripe:check-health', ['account' => $a->id])->assertSuccessful();
    expect(StripeAccountHealth::count())->toBe(1);

    $this->artisan('stripe:check-health', ['account' => 9999])->assertFailed();
});

// AC-2
it('derives the status from Stripe fields', function (array $payload, StripeHealthStatus $expected) {
    $account = StripeAccount::factory()->create();
    fakeStripeAccounts([$payload]);

    $health = app(StripeAccountHealthChecker::class)->check($account);

    expect($health->status)->toBe($expected);
})->with([
    'charges off' => [fn () => healthyPayload(['charges_enabled' => false]), StripeHealthStatus::Restricted],
    'payouts off' => [fn () => healthyPayload(['payouts_enabled' => false]), StripeHealthStatus::NeedsAttention],
    'past due' => [fn () => healthyPayload(['requirements' => ['past_due' => ['individual.id_number']]]), StripeHealthStatus::NeedsAttention],
    'currently due' => [fn () => healthyPayload(['requirements' => ['currently_due' => ['external_account']]]), StripeHealthStatus::NeedsAttention],
    'missing requirements' => [fn () => Arr::except(healthyPayload(), 'requirements'), StripeHealthStatus::Healthy],
    'healthy' => [fn () => healthyPayload(), StripeHealthStatus::Healthy],
]);

// AC-3
it('marks an account unreachable, keeps old data, and still checks the others', function () {
    $a = StripeAccount::factory()->create();
    $b = StripeAccount::factory()->create();
    StripeAccountHealth::factory()->create(['stripe_account_id' => $a->id, 'country' => 'GB']);
    fakeStripeAccounts([new RuntimeException('boom with sk_live_abc123SECRET key'), healthyPayload(['country' => 'US'])]);

    $this->artisan('stripe:check-health')->assertSuccessful();

    $ha = StripeAccountHealth::where('stripe_account_id', $a->id)->first();
    expect($ha->status)->toBe(StripeHealthStatus::Unreachable)
        ->and($ha->country)->toBe('GB')
        ->and($ha->charges_enabled)->toBeTrue()
        ->and($ha->last_error)->toContain('boom')
        ->and($ha->last_error)->not->toContain('sk_live_abc123SECRET'); // AC-11

    expect(StripeAccountHealth::where('stripe_account_id', $b->id)->first()->country)->toBe('US');
});

it('cuts last_error to 500 characters', function () {
    $account = StripeAccount::factory()->create();
    fakeStripeAccounts([new RuntimeException(str_repeat('x', 900))]);

    expect(app(StripeAccountHealthChecker::class)->check($account)->last_error)->toHaveLength(500);
});

// AC-4
it('only moves status_changed_at when the status changes', function () {
    $account = StripeAccount::factory()->create();
    $checker = app(StripeAccountHealthChecker::class);

    $this->travelTo(now()->subHour());
    fakeStripeAccounts([healthyPayload()]);
    $first = $checker->check($account);
    $firstChanged = $first->status_changed_at;
    expect($firstChanged)->not->toBeNull();

    $this->travelBack();
    fakeStripeAccounts([healthyPayload()]);
    $second = $checker->check($account);
    expect($second->status_changed_at->equalTo($firstChanged))->toBeTrue()
        ->and($second->last_checked_at->greaterThan($firstChanged))->toBeTrue();

    fakeStripeAccounts([healthyPayload(['charges_enabled' => false])]);
    $third = $checker->check($account);
    expect($third->status)->toBe(StripeHealthStatus::Restricted)
        ->and($third->status_changed_at->greaterThan($firstChanged))->toBeTrue();
});

// AC-5
it('schedules the check every 15 minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'stripe:check-health'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

// AC-6, AC-7
it('shows one card per account with performance numbers', function () {
    $checked = StripeAccount::factory()->create();
    $never = StripeAccount::factory()->create(['is_active' => false]);
    StripeAccountHealth::factory()->create(['stripe_account_id' => $checked->id]);

    $make = fn (string $status, $createdAt) => Payment::factory()->create([
        'stripe_account_id' => $checked->id, 'status' => $status, 'created_at' => $createdAt,
    ]);
    $make('completed', now()->subDays(2));
    $make('completed', now()->subDays(3));
    $make('failed', now()->subDays(1));
    $make('cancelled', now()->subDays(1));
    $make('completed', now()->subDays(20));
    $make('failed', now()->subDays(40));   // outside both windows
    $make('pending', now()->subHours(30)); // stuck
    $make('pending', now()->subHours(2));  // not stuck

    $this->actingAs(healthUser('admin'))
        ->get('/stripe-health')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('StripeHealth/Index')
            ->has('accounts', 2)
            ->where('accounts', function ($accounts) use ($checked, $never) {
                $c = collect($accounts)->firstWhere('id', $checked->id);
                $n = collect($accounts)->firstWhere('id', $never->id);

                return $n['health'] === null
                    && $c['performance'] === [
                        'completed_7d' => 2, 'failed_7d' => 1,
                        'completed_30d' => 3, 'failed_30d' => 1,
                        'stuck_pending' => 1,
                    ];
            }));
});

// AC-8
it('lets admin and account roles use the page and buttons, blocks agents', function () {
    $account = StripeAccount::factory()->create();
    fakeStripeAccounts(array_fill(0, 6, healthyPayload()));

    foreach (['admin', 'account'] as $role) {
        $user = healthUser($role);
        $this->actingAs($user)->get('/stripe-health')->assertOk();
        $this->actingAs($user)->post('/stripe-health/check')->assertRedirect();
        $this->actingAs($user)->post("/stripe-health/{$account->id}/check")->assertRedirect();
    }

    expect(StripeAccountHealth::where('stripe_account_id', $account->id)->first()->status)
        ->toBe(StripeHealthStatus::Healthy);

    $agent = healthUser('agent');
    $this->actingAs($agent)->get('/stripe-health')->assertForbidden();
    $this->actingAs($agent)->post('/stripe-health/check')->assertForbidden();
    $this->actingAs($agent)->post("/stripe-health/{$account->id}/check")->assertForbidden();
});

it('throttles Check now to 10 per minute', function () {
    StripeAccount::factory()->create();
    fakeStripeAccounts(array_fill(0, 11, healthyPayload()));
    $admin = healthUser('admin');

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($admin)->post('/stripe-health/check')->assertRedirect();
    }

    $this->actingAs($admin)->post('/stripe-health/check')->assertStatus(429);
});

// AC-9
it('includes the health status on the Stripe Accounts list', function () {
    $account = StripeAccount::factory()->create();
    StripeAccountHealth::factory()->create([
        'stripe_account_id' => $account->id, 'status' => StripeHealthStatus::Restricted,
    ]);

    $this->actingAs(healthUser('admin'))
        ->get(route('admin.stripe-accounts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stripeAccounts.0.health_status', 'restricted'));
});

// AC-10
it('checks health after creating an account, and saving survives a Stripe failure', function () {
    fakeStripeAccounts([healthyPayload()]);
    $payload = ['account_name' => 'New', 'publishable_key' => 'pk_test_abc123', 'secret_key' => 'sk_test_abc123'];

    $this->actingAs(healthUser('admin'))->post(route('admin.stripe-accounts.store'), $payload)
        ->assertSessionHasNoErrors();
    expect(StripeAccountHealth::first()->status)->toBe(StripeHealthStatus::Healthy);

    fakeStripeAccounts([new RuntimeException('Stripe is down')]);
    $this->actingAs(healthUser('admin'))->post(route('admin.stripe-accounts.store'), $payload + ['prefix' => 'B'])
        ->assertRedirect(route('admin.stripe-accounts.index'));

    expect(StripeAccount::count())->toBe(2)
        ->and(StripeAccountHealth::where('status', 'unreachable')->count())->toBe(1);
});

it('checks health on update only when the secret key changed', function () {
    $account = StripeAccount::factory()->create();
    fakeStripeAccounts([healthyPayload()]);
    $admin = healthUser('admin');
    $base = ['account_name' => 'Renamed', 'publishable_key' => 'pk_test_abc123'];

    $this->actingAs($admin)->put(route('admin.stripe-accounts.update', $account), $base)
        ->assertSessionHasNoErrors();
    expect(StripeAccountHealth::count())->toBe(0);

    $this->actingAs($admin)->put(route('admin.stripe-accounts.update', $account), $base + ['secret_key' => 'sk_test_newkey'])
        ->assertSessionHasNoErrors();
    expect(StripeAccountHealth::count())->toBe(1);
});

// AC-11
it('never exposes keys in page props', function () {
    $account = StripeAccount::factory()->create([
        'webhook_secret' => 'whsec_supersecretvalue',
    ]);
    $account->secret_key = 'sk_test_supersecretkey';
    $account->save();
    StripeAccountHealth::factory()->create(['stripe_account_id' => $account->id]);

    $response = $this->actingAs(healthUser('admin'))->get('/stripe-health');
    $listResponse = $this->actingAs(healthUser('admin'))->get(route('admin.stripe-accounts.index'));

    foreach ([$response, $listResponse] as $r) {
        expect($r->getContent())
            ->not->toContain('sk_test_supersecretkey')
            ->not->toContain('whsec_supersecretvalue');
    }
});

it('does not log key values when a check fails', function () {
    $account = StripeAccount::factory()->create();
    Log::spy();
    fakeStripeAccounts([new RuntimeException('bad key sk_live_LEAKME123')]);

    $health = app(StripeAccountHealthChecker::class)->check($account);

    expect($health->last_error)->not->toContain('LEAKME123');
    Log::shouldNotHaveReceived('error');
});
