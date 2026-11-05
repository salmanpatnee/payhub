<?php

use App\Models\KurvAccount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

function kurvUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->syncRoles([$role]);

    return $user;
}

function kurvForm(array $overrides = []): array
{
    return array_merge([
        'account_name' => 'Acme Kurv',
        'prefix' => 'ACME',
        'environment' => 'sandbox',
        'api_key' => 'kp_test_secret_value',
    ], $overrides);
}

// ---- Permissions (AC-1) ----

it('gives agent and account roles 403 on every account route', function (string $role) {
    $account = KurvAccount::factory()->create();
    $user = kurvUser($role);

    $this->actingAs($user)->get(route('admin.kurv-accounts.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.kurv-accounts.store'), kurvForm())->assertForbidden();
    $this->actingAs($user)->put(route('admin.kurv-accounts.update', $account), kurvForm())->assertForbidden();
    $this->actingAs($user)->post(route('admin.kurv-accounts.test-stored-connection', $account))->assertForbidden();
})->with(['agent', 'account']);

// ---- CRUD (AC-1) ----

it('creates an account with an encrypted key that is not mass assignable', function () {
    $this->actingAs(kurvUser('admin'))
        ->post(route('admin.kurv-accounts.store'), kurvForm())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.kurv-accounts.index'));

    $account = KurvAccount::firstOrFail();
    expect($account->api_key)->toBe('kp_test_secret_value')
        ->and($account->getRawOriginal('api_key'))->not->toContain('kp_test_secret_value');

    $filled = (new KurvAccount)->fill(['api_key' => 'x']);
    expect($filled->api_key)->toBeNull();
});

it('never sends the key to the edit page, only a flag', function () {
    $account = KurvAccount::factory()->create(['api_key' => 'kp_test_hidden']);

    $this->actingAs(kurvUser('admin'))->get(route('admin.kurv-accounts.edit', $account))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kurvAccount.has_api_key', true)
            ->missing('kurvAccount.api_key'));
});

it('refuses a key that does not match the environment', function (string $key, string $environment) {
    $this->actingAs(kurvUser('admin'))
        ->post(route('admin.kurv-accounts.store'), kurvForm(['api_key' => $key, 'environment' => $environment]))
        ->assertSessionHasErrors('api_key');

    expect(KurvAccount::count())->toBe(0);
})->with([
    'live key with sandbox' => ['kp_live_abc', 'sandbox'],
    'test key with production' => ['kp_test_abc', 'production'],
    'no prefix' => ['abc', 'sandbox'],
]);

it('keeps the stored key when the key is blank and checks the environment against it', function () {
    $account = KurvAccount::factory()->create(['api_key' => 'kp_test_keepme', 'environment' => 'sandbox']);
    $admin = kurvUser('admin');

    $this->actingAs($admin)->put(route('admin.kurv-accounts.update', $account), kurvForm(['api_key' => '', 'account_name' => 'Renamed']))
        ->assertSessionHasNoErrors();

    expect($account->fresh()->api_key)->toBe('kp_test_keepme')->and($account->fresh()->account_name)->toBe('Renamed');

    $this->actingAs($admin)->put(route('admin.kurv-accounts.update', $account), kurvForm(['api_key' => '', 'environment' => 'production']))
        ->assertSessionHasErrors('environment');

    expect($account->fresh()->environment)->toBe('sandbox');
});

it('activates, deactivates and deletes only when no payment uses it', function () {
    $admin = kurvUser('admin');
    $account = KurvAccount::factory()->create();

    $this->actingAs($admin)->patch(route('admin.kurv-accounts.deactivate', $account));
    expect($account->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch(route('admin.kurv-accounts.activate', $account));
    expect($account->fresh()->is_active)->toBeTrue();

    Payment::factory()->kurv()->create(['kurv_account_id' => $account->id]);
    $this->actingAs($admin)->delete(route('admin.kurv-accounts.destroy', $account))->assertSessionHas('error');
    expect(KurvAccount::count())->toBe(1);

    Payment::query()->forceDelete();
    $this->actingAs($admin)->delete(route('admin.kurv-accounts.destroy', $account));
    expect(KurvAccount::count())->toBe(0);
});

// ---- Test connection (AC-1) ----

it('maps Kurv replies to plain words on both test connection routes', function (int $status, ?string $message) {
    Http::fake(['*' => Http::response([], $status)]);
    $admin = kurvUser('admin');
    $account = KurvAccount::factory()->create(['api_key' => 'kp_test_stored']);

    foreach ([
        fn () => $this->actingAs($admin)->post(route('admin.kurv-accounts.test-connection'), ['api_key' => 'kp_test_typed', 'environment' => 'sandbox']),
        fn () => $this->actingAs($admin)->post(route('admin.kurv-accounts.test-stored-connection', $account)),
    ] as $call) {
        $response = $call();

        if ($message === null) {
            $response->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors(['kurv_api' => $message]);
        }
    }
})->with([
    'ok' => [200, null],
    'bad key' => [403, 'Wrong key or missing permission. Kurv did not accept this API key.'],
    'plan' => [422, 'Your Kurv plan does not allow this.'],
    'other' => [500, 'Kurv returned an unexpected reply. Please try again.'],
]);

it('sends the stored key as a bearer token to the sandbox host', function () {
    Http::fake(['*' => Http::response(['payments' => []], 200)]);
    $account = KurvAccount::factory()->create(['api_key' => 'kp_test_stored']);

    $this->actingAs(kurvUser('admin'))->post(route('admin.kurv-accounts.test-stored-connection', $account));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api-sandbox.kurv.app/payments')
        && $request->hasHeader('Authorization', 'Bearer kp_test_stored'));
});
