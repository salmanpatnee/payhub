<?php

use App\Models\AuthorizenetAccount;
use App\Models\Payment;
use App\Models\User;
use App\Support\CurrencySupportResolver;
use App\Support\ProviderAccountTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

function anetUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->syncRoles([$role]);

    return $user;
}

function fakeMerchantDetails(array $currencies = ['USD'], string $resultCode = 'Ok'): void
{
    Http::swap(new Factory); // drop earlier stubs: the first registered stub wins
    Http::fake([
        'apitest.authorize.net/*' => Http::response(
            "\xEF\xBB\xBF".json_encode([
                'currencies' => $currencies,
                'messages' => ['resultCode' => $resultCode, 'message' => [['code' => 'I00001', 'text' => 'Successful.']]],
            ]),
            200,
        ),
    ]);
}

function anetPayload(array $overrides = []): array
{
    return array_merge([
        'account_name' => 'Acme US',
        'prefix' => 'ACME',
        'api_login_id' => 'login123',
        'transaction_key' => 'tx-key-secret',
        'signature_key' => 'sig-key-secret',
        'environment' => 'sandbox',
    ], $overrides);
}

it('lets an admin create an account with encrypted, guarded keys', function () {
    fakeMerchantDetails();

    $this->actingAs(anetUser('admin'))
        ->post(route('admin.authorizenet-accounts.store'), anetPayload())
        ->assertRedirect(route('admin.authorizenet-accounts.index'))
        ->assertSessionHasNoErrors();

    $account = AuthorizenetAccount::firstOrFail();
    expect($account->transaction_key)->toBe('tx-key-secret')
        ->and($account->signature_key)->toBe('sig-key-secret')
        ->and($account->currency)->toBe('usd')
        ->and($account->is_active)->toBeTrue();

    // Stored encrypted, not plain.
    $raw = DB::table('authorizenet_accounts')->first();
    expect($raw->transaction_key)->not->toBe('tx-key-secret')
        ->and($raw->signature_key)->not->toBe('sig-key-secret');

    // Not mass assignable.
    $account->fill(['transaction_key' => 'hacked', 'signature_key' => 'hacked']);
    expect($account->transaction_key)->toBe('tx-key-secret');
});

it('rejects an account whose credentials Authorize.net refuses', function () {
    fakeMerchantDetails(resultCode: 'Error');

    $this->actingAs(anetUser('admin'))
        ->post(route('admin.authorizenet-accounts.store'), anetPayload())
        ->assertSessionHasErrors('authorizenet_api');

    expect(AuthorizenetAccount::count())->toBe(0);
});

it('exposes only has_* flags, never the keys', function () {
    $account = AuthorizenetAccount::factory()->create();
    $account->signature_key = 'sig-key-secret';
    $account->save();

    $admin = anetUser('admin');

    $this->actingAs($admin)
        ->get(route('admin.authorizenet-accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('authorizenetAccounts.0.has_signature_key', true)
            ->missing('authorizenetAccounts.0.transaction_key')
            ->missing('authorizenetAccounts.0.signature_key'));

    $this->actingAs($admin)
        ->get(route('admin.authorizenet-accounts.edit', $account))
        ->assertInertia(fn ($page) => $page
            ->where('authorizenetAccount.has_transaction_key', true)
            ->where('authorizenetAccount.has_signature_key', true)
            ->missing('authorizenetAccount.transaction_key')
            ->missing('authorizenetAccount.signature_key'));
});

it('keeps stored keys when update fields are blank and replaces them when given', function () {
    fakeMerchantDetails();
    $account = AuthorizenetAccount::factory()->create(['transaction_key' => 'old-key']);
    $account->signature_key = 'old-sig';
    $account->save();

    $admin = anetUser('admin');

    $this->actingAs($admin)
        ->put(route('admin.authorizenet-accounts.update', $account), anetPayload(['transaction_key' => '', 'signature_key' => '']))
        ->assertSessionHasNoErrors();

    $account->refresh();
    expect($account->transaction_key)->toBe('old-key')->and($account->signature_key)->toBe('old-sig');

    $this->actingAs($admin)
        ->put(route('admin.authorizenet-accounts.update', $account), anetPayload(['transaction_key' => 'new-key', 'signature_key' => 'new-sig']))
        ->assertSessionHasNoErrors();

    $account->refresh();
    expect($account->transaction_key)->toBe('new-key')->and($account->signature_key)->toBe('new-sig');
});

it('validates the environment and prefix', function () {
    $this->actingAs(anetUser('admin'))
        ->post(route('admin.authorizenet-accounts.store'), anetPayload(['environment' => 'staging', 'prefix' => 'bad prefix']))
        ->assertSessionHasErrors(['environment', 'prefix']);
});

it('activates, deactivates and deletes an account, blocking delete when it has payments', function () {
    $account = AuthorizenetAccount::factory()->create();
    $admin = anetUser('admin');

    $this->actingAs($admin)->patch(route('admin.authorizenet-accounts.deactivate', $account));
    expect($account->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch(route('admin.authorizenet-accounts.activate', $account));
    expect($account->refresh()->is_active)->toBeTrue();

    Payment::factory()->authorizenet()->create(['authorizenet_account_id' => $account->id]);
    $this->actingAs($admin)
        ->delete(route('admin.authorizenet-accounts.destroy', $account))
        ->assertSessionHas('error');
    expect(AuthorizenetAccount::count())->toBe(1);

    $empty = AuthorizenetAccount::factory()->create();
    $this->actingAs($admin)->delete(route('admin.authorizenet-accounts.destroy', $empty));
    expect(AuthorizenetAccount::find($empty->id))->toBeNull();
});

it('tests stored and unsaved credentials with one getMerchantDetails call and warns when USD is missing', function () {
    fakeMerchantDetails(['GBP']);
    $account = AuthorizenetAccount::factory()->create();
    $admin = anetUser('admin');

    $this->actingAs($admin)
        ->post(route('admin.authorizenet-accounts.test-stored-connection', $account))
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'verified') && str_contains($m, 'USD'));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/xml/v1/request.api')
        && isset($request['getMerchantDetailsRequest']['merchantAuthentication']['name'])
        && $request['getMerchantDetailsRequest']['merchantAuthentication']['name'] === $account->api_login_id);

    fakeMerchantDetails(['USD']);
    $this->actingAs($admin)
        ->post(route('admin.authorizenet-accounts.test-connection'), [
            'api_login_id' => 'abc', 'transaction_key' => 'def', 'environment' => 'sandbox',
        ])
        ->assertSessionHas('success', fn (string $m) => ! str_contains($m, 'does not list USD'));
});

it('reports a failed connection test', function () {
    fakeMerchantDetails(resultCode: 'Error');
    $account = AuthorizenetAccount::factory()->create();

    $this->actingAs(anetUser('admin'))
        ->post(route('admin.authorizenet-accounts.test-stored-connection', $account))
        ->assertSessionHasErrors('authorizenet_api');
});

it('forbids every non admin from the account routes', function (string $role) {
    $account = AuthorizenetAccount::factory()->create();
    $user = anetUser($role);

    $this->actingAs($user)->get(route('admin.authorizenet-accounts.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.authorizenet-accounts.store'), anetPayload())->assertForbidden();
    $this->actingAs($user)->put(route('admin.authorizenet-accounts.update', $account), anetPayload())->assertForbidden();
    $this->actingAs($user)->delete(route('admin.authorizenet-accounts.destroy', $account))->assertForbidden();
})->with(['agent']);

it('is USD only through the currency resolver and maps to its table', function () {
    expect(CurrencySupportResolver::supports('authorizenet', 'usd', 'usd'))->toBeTrue()
        ->and(CurrencySupportResolver::supports('authorizenet', 'usd', 'gbp'))->toBeFalse()
        ->and(CurrencySupportResolver::supports('authorizenet', 'usd', 'pkr'))->toBeFalse()
        ->and(ProviderAccountTable::for('authorizenet'))->toBe('authorizenet_accounts');
});

it('resolves the reference code prefix and account name for an Authorize.net payment', function () {
    $account = AuthorizenetAccount::factory()->create(['prefix' => 'ACME', 'account_name' => 'Acme US']);
    $payment = Payment::factory()->authorizenet()->create([
        'authorizenet_account_id' => $account->id,
        'reference_code' => 42,
    ]);

    expect($payment->formattedReferenceCode())->toBe('ACME-000042')
        ->and($payment->providerAccountName())->toBe('Acme US');
});
