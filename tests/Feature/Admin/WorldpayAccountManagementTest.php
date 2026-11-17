<?php

use App\Models\Payment;
use App\Models\User;
use App\Models\WorldpayAccount;
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

function wpUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->syncRoles([$role]);

    return $user;
}

function wpForm(array $overrides = []): array
{
    return array_merge([
        'account_name' => 'Acme Worldpay',
        'prefix' => 'ACME',
        'environment' => 'try',
        'entity_ref' => 'PO1234567890',
        'api_username' => 'user-secret',
        'api_password' => 'pass-secret',
    ], $overrides);
}

it('lets only an admin manage Worldpay accounts', function (string $role, int $status) {
    $this->actingAs(wpUser($role))->get('/admin/worldpay-accounts')->assertStatus($status);
})->with([['admin', 200], ['agent', 403], ['account', 403]]);

it('creates an account with encrypted secrets and never sends them back', function () {
    $admin = wpUser('admin');

    $this->actingAs($admin)->post('/admin/worldpay-accounts', wpForm())->assertRedirect(route('admin.worldpay-accounts.index'));

    $account = WorldpayAccount::sole();
    expect($account->api_username)->toBe('user-secret')
        ->and($account->api_password)->toBe('pass-secret')
        ->and($account->webhook_secret)->toBeNull();

    $this->actingAs($admin)->get("/admin/worldpay-accounts/{$account->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('worldpayAccount.has_api_username', true)
            ->where('worldpayAccount.has_api_password', true)
            ->where('worldpayAccount.has_webhook_secret', false)
            ->missing('worldpayAccount.api_username')
            ->missing('worldpayAccount.api_password')
            ->missing('worldpayAccount.webhook_secret'));

    $this->actingAs($admin)->get('/admin/worldpay-accounts')->assertDontSee('pass-secret');
});

it('validates the entity, prefix, environment and the webhook pair', function (array $bad, string $field) {
    $this->actingAs(wpUser('admin'))->post('/admin/worldpay-accounts', wpForm($bad))->assertSessionHasErrors($field);
})->with([
    'bad entity' => [['entity_ref' => 'PO 123!'], 'entity_ref'],
    'entity too long' => [['entity_ref' => str_repeat('A', 65)], 'entity_ref'],
    'bad environment' => [['environment' => 'sandbox'], 'environment'],
    'bad prefix' => [['prefix' => 'abc'], 'prefix'],
    'key id without secret' => [['webhook_key_id' => 'key1'], 'webhook_secret'],
    'secret without key id' => [['webhook_secret' => 'whsec'], 'webhook_key_id'],
]);

it('keeps saved secrets when the fields are blank and replaces them when filled', function () {
    $account = new WorldpayAccount(['account_name' => 'A', 'entity_ref' => 'PO1', 'environment' => 'try']);
    $account->api_username = 'old-user';
    $account->api_password = 'old-pass';
    $account->save();

    $this->actingAs(wpUser('admin'))->put("/admin/worldpay-accounts/{$account->id}", wpForm(['api_username' => '', 'api_password' => '', 'entity_ref' => 'PO1']))
        ->assertSessionHasNoErrors();
    expect($account->fresh()->api_username)->toBe('old-user')->and($account->fresh()->api_password)->toBe('old-pass');

    $this->actingAs(wpUser('admin'))->put("/admin/worldpay-accounts/{$account->id}", wpForm(['api_password' => 'new-pass', 'api_username' => '', 'entity_ref' => 'PO1']));
    expect($account->fresh()->api_password)->toBe('new-pass')->and($account->fresh()->api_username)->toBe('old-user');
});

it('locks the environment and entity once a payment uses the account', function () {
    $payment = Payment::factory()->worldpay()->create();
    $account = $payment->worldpayAccount;

    $response = $this->actingAs(wpUser('admin'))->put("/admin/worldpay-accounts/{$account->id}", wpForm([
        'entity_ref' => 'CHANGED', 'api_username' => '', 'api_password' => '',
    ]));

    $response->assertSessionHasErrors('entity_ref');
    expect(session('errors')->first('entity_ref'))->toBe('This account already has payments. Create a new account to change the environment or entity.');

    // Other fields still change.
    $this->actingAs(wpUser('admin'))->put("/admin/worldpay-accounts/{$account->id}", wpForm([
        'account_name' => 'Renamed', 'entity_ref' => $account->entity_ref, 'environment' => $account->environment,
        'api_username' => '', 'api_password' => '',
    ]))->assertSessionHasNoErrors();
    expect($account->fresh()->account_name)->toBe('Renamed');
});

it('activates, deactivates and blocks delete while payments use the account', function () {
    $admin = wpUser('admin');
    $payment = Payment::factory()->worldpay()->create();
    $account = $payment->worldpayAccount;

    $this->actingAs($admin)->patch("/admin/worldpay-accounts/{$account->id}/deactivate");
    expect($account->fresh()->is_active)->toBeFalse();
    $this->actingAs($admin)->patch("/admin/worldpay-accounts/{$account->id}/activate");
    expect($account->fresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->delete("/admin/worldpay-accounts/{$account->id}")->assertSessionHas('error');
    expect(WorldpayAccount::count())->toBe(1);

    $free = WorldpayAccount::factory()->create();
    $this->actingAs($admin)->delete("/admin/worldpay-accounts/{$free->id}");
    expect(WorldpayAccount::find($free->id))->toBeNull();
});

it('gives plain messages from both test connection routes', function (int $status, ?string $message) {
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => []]], $status)]);
    $admin = wpUser('admin');
    $account = WorldpayAccount::factory()->create();

    foreach ([
        fn () => $this->actingAs($admin)->post('/admin/worldpay-accounts/test-connection', ['api_username' => 'u', 'api_password' => 'p', 'environment' => 'try']),
        fn () => $this->actingAs($admin)->post("/admin/worldpay-accounts/{$account->id}/test-connection"),
    ] as $call) {
        $response = $call();

        if ($message === null) {
            $response->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors(['worldpay_api' => $message]);
        }
    }

    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'transactionReference=payhub-test-'));
})->with([
    [200, null],
    [401, 'Worldpay did not accept this username and password. Check they belong to this environment (Try or Live).'],
    [403, 'This Worldpay user may not look up payments. Ask Worldpay to enable Payment Queries.'],
    [500, 'Could not reach Worldpay. Try again later.'],
]);

it('fills a blank username or password from the saved account on the unsaved route', function () {
    Http::fake(['*' => Http::response(['_embedded' => ['payments' => []]], 200)]);
    $account = WorldpayAccount::factory()->create();

    $this->actingAs(wpUser('admin'))->post('/admin/worldpay-accounts/test-connection', [
        'environment' => 'try', 'account_id' => $account->id, 'api_password' => 'typed-pass',
    ])->assertSessionHasNoErrors();

    Http::assertSent(function ($request) use ($account) {
        $auth = $request->header('Authorization')[0] ?? '';

        return $auth === 'Basic '.base64_encode($account->api_username.':typed-pass');
    });
});
