<?php

use App\Models\Payment;
use App\Models\User;
use App\Models\WhopAccount;
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

function whopUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->syncRoles([$role]);

    return $user;
}

function whopForm(array $overrides = []): array
{
    return array_merge([
        'account_name' => 'Acme Whop',
        'prefix' => 'ACME',
        'company_id' => 'biz_test123',
        'environment' => 'sandbox',
        'api_key' => 'apik_secret_value',
    ], $overrides);
}

// ---- Permissions (AC-1) ----

it('gives agent and account roles 403 on every account route', function (string $role) {
    $account = WhopAccount::factory()->create();
    $user = whopUser($role);

    $this->actingAs($user)->get(route('admin.whop-accounts.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.whop-accounts.store'), whopForm())->assertForbidden();
    $this->actingAs($user)->put(route('admin.whop-accounts.update', $account), whopForm())->assertForbidden();
    $this->actingAs($user)->post(route('admin.whop-accounts.test-stored-connection', $account))->assertForbidden();
})->with(['agent', 'account']);

// ---- CRUD (AC-1) ----

it('creates an account with encrypted secrets that are not mass assignable', function () {
    $this->actingAs(whopUser('admin'))
        ->post(route('admin.whop-accounts.store'), whopForm(['webhook_secret' => 'ws_abc']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.whop-accounts.index'));

    $account = WhopAccount::firstOrFail();
    expect($account->api_key)->toBe('apik_secret_value')
        ->and($account->webhook_secret)->toBe('ws_abc')
        ->and($account->getRawOriginal('api_key'))->not->toContain('apik_secret_value')
        ->and($account->getRawOriginal('webhook_secret'))->not->toContain('ws_abc');

    $filled = (new WhopAccount)->fill(['api_key' => 'x', 'webhook_secret' => 'y']);
    expect($filled->api_key)->toBeNull()->and($filled->webhook_secret)->toBeNull();
});

it('validates the required fields and environment', function () {
    $this->actingAs(whopUser('admin'))
        ->post(route('admin.whop-accounts.store'), whopForm(['company_id' => '', 'api_key' => '', 'environment' => 'live']))
        ->assertSessionHasErrors(['company_id', 'api_key', 'environment']);
});

it('refuses a second active account with the same company id', function () {
    WhopAccount::factory()->create(['company_id' => 'biz_test123']);

    $this->actingAs(whopUser('admin'))
        ->post(route('admin.whop-accounts.store'), whopForm())
        ->assertSessionHasErrors('company_id');

    WhopAccount::query()->update(['is_active' => false]);

    $this->actingAs(whopUser('admin'))
        ->post(route('admin.whop-accounts.store'), whopForm())
        ->assertSessionHasNoErrors();
});

it('never sends secrets to the edit page, only flags', function () {
    $account = WhopAccount::factory()->create(['api_key' => 'apik_hidden', 'webhook_secret' => 'ws_hidden']);

    $this->actingAs(whopUser('admin'))
        ->get(route('admin.whop-accounts.edit', $account))
        ->assertInertia(fn (Assert $page) => $page
            ->where('whopAccount.has_api_key', true)
            ->where('whopAccount.has_webhook_secret', true)
            ->missing('whopAccount.api_key')
            ->missing('whopAccount.webhook_secret'))
        ->assertDontSee('apik_hidden')
        ->assertDontSee('ws_hidden');
});

it('keeps stored secrets when update fields are blank, and replaces them when filled', function () {
    $account = WhopAccount::factory()->create(['api_key' => 'apik_old', 'webhook_secret' => 'ws_old']);
    $admin = whopUser('admin');

    $this->actingAs($admin)->put(route('admin.whop-accounts.update', $account), whopForm(['company_id' => $account->company_id, 'api_key' => '', 'webhook_secret' => '']))
        ->assertSessionHasNoErrors();
    expect($account->fresh()->api_key)->toBe('apik_old')->and($account->fresh()->webhook_secret)->toBe('ws_old');

    $this->actingAs($admin)->put(route('admin.whop-accounts.update', $account), whopForm(['company_id' => $account->company_id, 'api_key' => 'apik_new', 'webhook_secret' => 'ws_new']));
    expect($account->fresh()->api_key)->toBe('apik_new')->and($account->fresh()->webhook_secret)->toBe('ws_new');
});

it('deactivates and activates, and blocks activation when the company id is taken', function () {
    $admin = whopUser('admin');
    $a = WhopAccount::factory()->create(['company_id' => 'biz_same']);

    $this->actingAs($admin)->patch(route('admin.whop-accounts.deactivate', $a));
    expect($a->fresh()->is_active)->toBeFalse();

    $b = WhopAccount::factory()->create(['company_id' => 'biz_same']);

    $this->actingAs($admin)->patch(route('admin.whop-accounts.activate', $a))->assertSessionHas('error');
    expect($a->fresh()->is_active)->toBeFalse();

    $b->update(['is_active' => false]);
    $this->actingAs($admin)->patch(route('admin.whop-accounts.activate', $a));
    expect($a->fresh()->is_active)->toBeTrue();
});

it('will not delete an account that has payments', function () {
    $account = WhopAccount::factory()->create();
    Payment::factory()->whop()->create(['whop_account_id' => $account->id]);

    $this->actingAs(whopUser('admin'))->delete(route('admin.whop-accounts.destroy', $account))->assertSessionHas('error');

    expect(WhopAccount::count())->toBe(1);
});

// ---- Test connection (AC-1) ----

it('maps Whop replies to plain words', function (int $status, ?string $message) {
    Http::fake(['*' => Http::response([], $status)]);
    $account = WhopAccount::factory()->create();

    $response = $this->actingAs(whopUser('admin'))->post(route('admin.whop-accounts.test-stored-connection', $account));

    $message === null
        ? $response->assertSessionHasNoErrors()
        : $response->assertSessionHasErrors(['whop_api' => $message]);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer apik_placeholder_for_dev_only'));
})->with([
    'ok' => [200, null],
    'wrong key' => [401, 'Wrong key. Whop did not accept this API key.'],
    'missing permission' => [403, 'Missing permission. This API key is not allowed to read the company.'],
    'wrong company' => [404, 'Wrong company ID. Whop could not find this company.'],
    'other' => [500, 'Whop returned an unexpected reply. Please try again.'],
]);

it('tests typed values before saving', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $this->actingAs(whopUser('admin'))
        ->post(route('admin.whop-accounts.test-connection'), ['api_key' => 'apik_x', 'company_id' => 'biz_x', 'environment' => 'sandbox'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'biz_x') && str_contains($request->url(), 'sandbox-api'));
});

// ---- Register command (AC-12) ----

it('registers a webhook and stores the secret encrypted', function () {
    Http::fake(['*/webhooks' => Http::response(['id' => 'wh_1', 'secret' => 'ws_fresh'], 200)]);
    $account = WhopAccount::factory()->create(['webhook_secret' => null]);

    $this->artisan('whop:register-webhook', ['account' => $account->id, '--url' => 'https://example.test/webhook/whop/1'])
        ->assertSuccessful();

    expect($account->fresh()->webhook_secret)->toBe('ws_fresh')
        ->and($account->fresh()->getRawOriginal('webhook_secret'))->not->toContain('ws_fresh');

    Http::assertSent(fn ($request) => $request['url'] === 'https://example.test/webhook/whop/1'
        && $request['events'] === ['payment.succeeded', 'payment.failed']);
});

it('refuses a missing or non https url without calling Whop', function (?string $url) {
    Http::fake();
    $account = WhopAccount::factory()->create();

    $this->artisan('whop:register-webhook', array_filter(['account' => $account->id, '--url' => $url]))->assertFailed();

    Http::assertNothingSent();
})->with([null, 'http://example.test/webhook/whop/1']);

it('warns before replacing an existing secret', function () {
    Http::fake(['*/webhooks' => Http::response(['id' => 'wh_1', 'secret' => 'ws_new'], 200)]);
    $account = WhopAccount::factory()->create(['webhook_secret' => 'ws_old']);

    $this->artisan('whop:register-webhook', ['account' => $account->id, '--url' => 'https://example.test/x'])
        ->expectsConfirmation('This account already has a webhook secret. Registering again replaces it. Continue?', 'no')
        ->assertFailed();

    expect($account->fresh()->webhook_secret)->toBe('ws_old');
    Http::assertNothingSent();
});
