<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\User;
use App\Models\WhopAccount;
use App\Services\Whop\WhopClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Cache::flush();
    RateLimiter::clear('whop-checkout');
});

function adminForWhopCheckout(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function whopCheckoutFormPayload(WhopAccount $account, array $overrides = []): array
{
    return array_merge([
        'brand_id' => Brand::factory()->create()->id,
        'relationship_manager_id' => RelationshipManager::factory()->create()->id,
        'provider' => 'whop',
        'account_id' => $account->id,
        'currency' => 'usd',
        'amount' => '12.34',
        'client_name' => 'Alice Smith',
        'client_email' => 'alice@example.com',
        'package' => 'standard',
    ], $overrides);
}

function whopCheckoutPayment(array $account = [], array $payment = []): Payment
{
    $account = WhopAccount::factory()->create($account);

    return Payment::factory()->whop()->create(array_merge([
        'status' => 'pending',
        'amount' => 1234,
        'currency' => 'usd',
        'whop_account_id' => $account->id,
    ], $payment));
}

function giveWhopConsent(Payment $payment): void
{
    $payment->consents()->create([
        'policy_versions' => ['terms' => '1', 'refund' => '1', 'privacy' => '1'],
        'ip_address' => '127.0.0.1',
        'accepted_at' => now(),
    ]);
}

function fakeWhopCheckoutReply(string $path = 'https://sandbox.whop.com/checkout/ch_abc123/'): void
{
    Http::fake([
        '*/checkout_configurations' => Http::response(['id' => 'ch_abc123', 'purchase_url' => $path], 200),
    ]);
}

it('creates one checkout configuration from the payment record and saves it', function () {
    fakeWhopCheckoutReply();
    $payment = whopCheckoutPayment();
    giveWhopConsent($payment);

    // Anything the client sends about the price is ignored.
    $this->postJson("/pay/{$payment->uuid}/whop/checkout", ['amount' => 1, 'currency' => 'gbp', 'account_id' => 999])
        ->assertOk()
        ->assertExactJson([
            'mode' => 'embed',
            'checkout_configuration_id' => 'ch_abc123',
            'purchase_url' => 'https://sandbox.whop.com/checkout/ch_abc123/',
        ]);

    Http::assertSent(function ($request) use ($payment) {
        $body = $request->data();

        return str_ends_with($request->url(), '/checkout_configurations')
            && $body['mode'] === 'payment'
            && $body['account_id'] === $payment->whopAccount->company_id
            && $body['variant']['initial_price'] === 12.34
            && $body['variant']['currency'] === 'usd'
            && $body['variant']['billing_type'] === 'one_time'
            && $body['variant']['release_method'] === 'buy_now'
            && $body['variant']['visibility'] === 'hidden'
            && $body['variant']['adaptive_pricing_enabled'] === false
            && $body['variant']['payment_method_configuration']['enabled'] === ['card', 'apple_pay', 'google_pay', 'paypal', 'us_bank_account']
            && $body['variant']['payment_method_configuration']['include_platform_defaults'] === false
            && ! isset($body['plan'])
            && $body['variant']['description'] === $payment->formattedReferenceCode()
            && $body['variant']['title'] === $body['variant']['description']
            && $body['metadata']['payment_id'] === $payment->uuid
            && str_starts_with($body['redirect_url'], 'http');
    });

    $payment->refresh();
    expect($payment->whop_checkout_configuration_id)->toBe('ch_abc123')
        ->and($payment->whop_purchase_url)->toBe('https://sandbox.whop.com/checkout/ch_abc123/')
        ->and($payment->whop_charge_started_at)->not->toBeNull()
        ->and($payment->status)->toBe('pending');
});

it('reuses the saved checkout and makes no second Whop call, and the mode in the reply is the current one', function () {
    fakeWhopCheckoutReply();
    $payment = whopCheckoutPayment();
    giveWhopConsent($payment);

    $this->postJson("/pay/{$payment->uuid}/whop/checkout")->assertOk();

    $payment->whopAccount->update(['checkout_mode' => 'redirect']);

    $this->postJson("/pay/{$payment->uuid}/whop/checkout")
        ->assertOk()
        ->assertJsonPath('mode', 'redirect')
        ->assertJsonPath('checkout_configuration_id', 'ch_abc123');

    Http::assertSentCount(1);
});

it('returns 422 with no Whop call when there is no consent record', function () {
    fakeWhopCheckoutReply();
    $payment = whopCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/whop/checkout")->assertStatus(422);

    Http::assertNothingSent();
});

it('returns 422 for a non pending payment, another provider, or an inactive account', function () {
    fakeWhopCheckoutReply();

    $completed = whopCheckoutPayment(payment: ['status' => 'completed']);
    giveWhopConsent($completed);
    $this->postJson("/pay/{$completed->uuid}/whop/checkout")->assertStatus(422);

    $stripe = Payment::factory()->create(['status' => 'pending']);
    giveWhopConsent($stripe);
    $this->postJson("/pay/{$stripe->uuid}/whop/checkout")->assertStatus(422);

    $inactive = whopCheckoutPayment(['is_active' => false]);
    giveWhopConsent($inactive);
    $this->postJson("/pay/{$inactive->uuid}/whop/checkout")->assertStatus(422);

    Http::assertNothingSent();
});

it('answers 502 and saves nothing when Whop fails', function () {
    Log::spy();
    Http::fake(['*/checkout_configurations' => Http::response(['error' => 'secret internal text'], 500)]);
    $payment = whopCheckoutPayment();
    giveWhopConsent($payment);

    $this->postJson("/pay/{$payment->uuid}/whop/checkout")
        ->assertStatus(502)
        ->assertJsonMissing(['error' => 'secret internal text']);

    $payment->refresh();
    expect($payment->whop_checkout_configuration_id)->toBeNull()
        ->and($payment->whop_charge_started_at)->toBeNull();
});

it('answers 502 for an unreadable reply', function () {
    Http::fake(['*/checkout_configurations' => Http::response(['id' => 'nope'], 200)]);
    $payment = whopCheckoutPayment();
    giveWhopConsent($payment);

    $this->postJson("/pay/{$payment->uuid}/whop/checkout")->assertStatus(502);

    expect($payment->refresh()->whop_checkout_configuration_id)->toBeNull();
});

it('answers 409 when another request holds the lock', function () {
    fakeWhopCheckoutReply();
    $payment = whopCheckoutPayment();
    giveWhopConsent($payment);

    $held = Cache::lock('whop:checkout:'.$payment->id, 60);
    expect($held->get())->toBeTrue();

    // Cannot wait 5 seconds in a test: shrink by pre-holding and expecting 409 after block().
    $this->postJson("/pay/{$payment->uuid}/whop/checkout")->assertStatus(409);

    Http::assertNothingSent();
    $held->release();
})->skip(fn () => ! in_array(config('cache.default'), ['array', 'database', 'redis', 'file'], true));

it('limits checkout calls to 10 per payment per hour, reuse calls included', function () {
    fakeWhopCheckoutReply();
    $payment = whopCheckoutPayment();
    giveWhopConsent($payment);

    foreach (range(1, 10) as $i) {
        $this->postJson("/pay/{$payment->uuid}/whop/checkout")->assertOk();
    }

    $this->postJson("/pay/{$payment->uuid}/whop/checkout")
        ->assertStatus(429)
        ->assertJson(['message' => 'Too many attempts. Please try again later.']);

    Http::assertSentCount(1);
});

it('reports state only from the payment status and makes no Whop call', function () {
    Http::fake();

    $pending = whopCheckoutPayment();
    $this->getJson("/pay/{$pending->uuid}/whop/status")->assertOk()->assertExactJson(['state' => 'waiting']);

    $pending->update(['status' => 'completed']);
    $this->getJson("/pay/{$pending->uuid}/whop/status")->assertExactJson(['state' => 'completed']);

    $pending->update(['status' => 'cancelled']);
    $this->getJson("/pay/{$pending->uuid}/whop/status")->assertExactJson(['state' => 'unavailable']);

    $stripe = Payment::factory()->create();
    $this->getJson("/pay/{$stripe->uuid}/whop/status")->assertNotFound();

    Http::assertNothingSent();
});

it('renders the Whop pay page, never the Stripe page, and keeps secrets out of the props', function () {
    $payment = whopCheckoutPayment(payment: [
        'whop_checkout_configuration_id' => 'ch_secretid',
        'whop_purchase_url' => 'https://sandbox.whop.com/checkout/ch_secretid/',
    ]);

    $response = $this->get("/pay/{$payment->uuid}?status=succeeded&payment=pay_1");

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('ClientPayment/PayWhop')
        ->where('mode', 'embed')
        ->where('hasCheckout', true)
        ->where('hasConsent', false)
        ->where('returnStatus', 'succeeded')
        ->missing('payment.client_email')
    );

    expect($response->getContent())
        ->not->toContain('ch_secretid')
        ->not->toContain('apik_placeholder_for_dev_only');
});

it('ignores an unrecognised return status value', function () {
    $payment = whopCheckoutPayment();

    $this->get("/pay/{$payment->uuid}?status=<script>")
        ->assertInertia(fn ($page) => $page->where('returnStatus', null));
});

it('redirects a completed Whop payment to Success before the Unavailable guard', function () {
    $payment = whopCheckoutPayment(payment: ['status' => 'completed']);

    $this->get("/pay/{$payment->uuid}")->assertRedirect(route('pay.success', $payment));
});

it('shows Unavailable for an inactive account', function () {
    $payment = whopCheckoutPayment(['is_active' => false]);

    $this->get("/pay/{$payment->uuid}")
        ->assertInertia(fn ($page) => $page->component('ClientPayment/Unavailable'));
});

it('adds the Whop hosts to the CSP only for an embed account on the Whop pay page', function () {
    $embed = whopCheckoutPayment(['checkout_mode' => 'embed']);
    $redirect = whopCheckoutPayment(['checkout_mode' => 'redirect']);
    $stripe = Payment::factory()->create(['status' => 'pending']);

    $embedCsp = $this->get("/pay/{$embed->uuid}")->headers->get('Content-Security-Policy');
    expect($embedCsp)->toContain('https://cdn.whop.com')->toContain('https://sandbox-api.whop.com')
        ->toContain('https://www.paypal.com')->toContain('https://pay.google.com')
        ->toContain('https://applepay.cdn-apple.com')->toContain('https://cdn.plaid.com')
        ->not->toContain("'unsafe-eval'");

    foreach ([$redirect, $stripe] as $payment) {
        expect($this->get("/pay/{$payment->uuid}")->headers->get('Content-Security-Policy'))
            ->not->toContain('whop.com')->not->toContain('paypal.com')->not->toContain('plaid.com');
    }
});

it('blocks deleting and brand changes once the checkout configuration exists', function () {
    $payment = whopCheckoutPayment(payment: [
        'whop_checkout_configuration_id' => 'ch_abc123',
        'whop_charge_started_at' => now(),
    ]);
    $other = Brand::factory()->create();

    $this->actingAs(adminForWhopCheckout())->delete("/payments/{$payment->uuid}");
    expect(Payment::find($payment->id))->not->toBeNull();

    $payload = whopCheckoutFormPayload($payment->whopAccount, ['brand_id' => $other->id, 'amount' => '12.34']);

    $this->actingAs(adminForWhopCheckout())->patch("/payments/{$payment->uuid}", $payload)->assertSessionHasErrors('amount');
    expect($payment->refresh()->brand_id)->not->toBe($other->id);
});

it('allows deleting a Whop payment that has no checkout configuration yet', function () {
    $payment = whopCheckoutPayment();

    $this->actingAs(adminForWhopCheckout())->delete("/payments/{$payment->uuid}");

    expect(Payment::find($payment->id))->toBeNull();
});

it('builds the Whop purchase URL from the environment checkout host', function () {
    $sandbox = new WhopClient('k', 'biz_1', 'sandbox');
    $prod = new WhopClient('k', 'biz_1', 'production');

    expect($sandbox->purchaseUrl('/checkout/ch_1/'))->toBe('https://sandbox.whop.com/checkout/ch_1/')
        ->and($prod->purchaseUrl('/checkout/ch_1/'))->toBe('https://whop.com/checkout/ch_1/')
        ->and($prod->purchaseUrl('https://whop.com/checkout/ch_1/'))->toBe('https://whop.com/checkout/ch_1/');
});

it('saves checkout_mode on the account form and rejects an invalid value', function () {
    $base = [
        'account_name' => 'Brand Whop',
        'company_id' => 'biz_modeTest00001',
        'environment' => 'sandbox',
        'api_key' => 'apik_test',
    ];

    $this->actingAs(adminForWhopCheckout())->post('/admin/whop-accounts', $base + ['checkout_mode' => 'redirect'])
        ->assertSessionHasNoErrors();
    expect(WhopAccount::where('company_id', 'biz_modeTest00001')->value('checkout_mode'))->toBe('redirect');

    $this->actingAs(adminForWhopCheckout())->post('/admin/whop-accounts', array_merge($base, ['company_id' => 'biz_modeTest00002', 'checkout_mode' => 'iframe']))
        ->assertSessionHasErrors('checkout_mode');

    // Omitted: the column default (embed) applies.
    $this->actingAs(adminForWhopCheckout())->post('/admin/whop-accounts', array_merge($base, ['company_id' => 'biz_modeTest00003']))
        ->assertSessionHasNoErrors();
    expect(WhopAccount::where('company_id', 'biz_modeTest00003')->value('checkout_mode'))->toBe('embed');
});
