<?php

use App\Models\Payment;
use App\Models\RevolutAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const APPLE_FILE_URL = '/.well-known/apple-developer-merchantid-domain-association';

it('serves square\'s apple pay file by default', function () {
    $this->get(APPLE_FILE_URL)
        ->assertOk()
        ->assertSee(file_get_contents(resource_path('apple-pay/square')), false);
});

it('serves revolut\'s apple pay file when the site uses revolut', function () {
    config(['services.apple_pay.provider' => 'revolut']);

    $this->get(APPLE_FILE_URL)
        ->assertOk()
        ->assertSee(file_get_contents(resource_path('apple-pay/revolut')), false);
});

it('serves no file for an unknown provider', function () {
    config(['services.apple_pay.provider' => 'whop']);

    $this->get(APPLE_FILE_URL)->assertNotFound();
});

it('offers apple pay on the revolut pay page only when the site uses revolut', function (string $provider, bool $expected) {
    config(['services.apple_pay.provider' => $provider]);
    Http::fake(['*/api/orders' => Http::response(['id' => 'ord_ap', 'token' => 'tok_ap', 'state' => 'pending'], 201)]);
    $account = RevolutAccount::factory()->create();
    $payment = Payment::factory()->revolut()->create([
        'revolut_account_id' => $account->id,
        'revolut_order_id' => null,
        'status' => 'pending',
    ]);

    $this->get("/pay/{$payment->uuid}")
        ->assertInertia(fn ($page) => $page->component('ClientPayment/PayRevolut')->where('applePay', $expected));
})->with([
    'square site' => ['square', false],
    'revolut site' => ['revolut', true],
]);

it('registers the domain with revolut for every active account', function () {
    config(['services.apple_pay.provider' => 'revolut']);
    Http::fake(['*/api/apple-pay/domains/register' => Http::response(null, 204)]);
    RevolutAccount::factory()->count(2)->create(['is_active' => true]);
    RevolutAccount::factory()->create(['is_active' => false]);

    $this->artisan('revolut:register-apple-pay-domain', ['--domain' => 'pay.example.com'])
        ->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/apple-pay/domains/register')
        && $request['domain'] === 'pay.example.com');
});

it('refuses to register with revolut on a site that serves square\'s file', function () {
    Http::fake();
    RevolutAccount::factory()->create();

    $this->artisan('revolut:register-apple-pay-domain', ['--domain' => 'pay.example.com'])
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails without showing revolut text when revolut refuses the domain', function () {
    config(['services.apple_pay.provider' => 'revolut']);
    Http::fake(['*/api/apple-pay/domains/register' => Http::response(['message' => 'secret detail'], 422)]);
    RevolutAccount::factory()->create();

    $this->artisan('revolut:register-apple-pay-domain', ['--domain' => 'pay.example.com'])
        ->doesntExpectOutputToContain('secret detail')
        ->assertFailed();
});
