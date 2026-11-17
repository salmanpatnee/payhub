<?php

use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Models\WorldpayLookupAttempt;
use App\Models\WorldpayPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake([SendPaymentNotification::class]);
});

function wpCheckoutPayment(array $overrides = [], bool $consent = true): Payment
{
    $payment = Payment::factory()->worldpay()->create(array_merge([
        'amount' => 1234, 'status' => 'pending',
    ], $overrides));

    if ($consent) {
        $payment->consents()->create([
            'policy_versions' => ['terms' => '1'], 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'accepted_at' => now(),
        ]);
    }

    return $payment;
}

function wpFakeCreate(string $host = 'hpp-sandbox.worldpay.com'): void
{
    Http::fake([
        '*payment_pages' => Http::response(['_links' => ['self' => ['href' => 'x']], 'url' => "https://{$host}/app/hpp/integration/transaction/abc?cs=SECRET"], 200),
        '*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => []]], 200),
    ]);
}

function wpCreateCalls(): int
{
    return Http::recorded(fn ($request) => str_ends_with($request->url(), '/payment_pages'))->count();
}

it('creates one page from the saved amount and returns only the url', function () {
    wpFakeCreate();
    $payment = wpCheckoutPayment();
    $payment->brand->update(['name' => "Acme & Sons' Ltd / Group Holdings International"]);

    $response = $this->postJson("/pay/{$payment->uuid}/worldpay/checkout", ['amount' => 1, 'currency' => 'usd', 'account_id' => 999]);

    $response->assertOk()->assertExactJson(['url' => 'https://hpp-sandbox.worldpay.com/app/hpp/integration/transaction/abc?cs=SECRET']);

    Http::assertSent(function ($request) use ($payment) {
        if (! str_ends_with($request->url(), '/payment_pages')) {
            return false;
        }

        $body = $request->data();

        return $request->hasHeader('Content-Type', 'application/vnd.worldpay.payment_pages-v1.hal+json')
            && $request->hasHeader('Accept', 'application/vnd.worldpay.payment_pages-v1.hal+json')
            && $body['transactionReference'] === $payment->uuid.'-1'
            && $body['merchant']['entity'] === $payment->worldpayAccount->entity_ref
            && $body['value'] === ['amount' => 1234, 'currency' => 'GBP']
            && $body['expiry'] === 259200
            && preg_match('/^[A-Za-z0-9\-., ]{1,24}$/', $body['narrative']['line1']) === 1
            && $body['resultURLs']['successURL'] === route('pay.show', $payment).'?worldpay_return=success'
            && count($body['resultURLs']) === 6;
    });

    $payment->refresh();
    expect($payment->worldpay_charge_started_at)->not->toBeNull();
    $page = WorldpayPage::sole();
    expect($page->sequence)->toBe(1)->and($page->url)->toContain('hpp-sandbox.worldpay.com');
});

it('accepts the second Try host and refuses a page on any other host or over http', function (string $url, int $status) {
    Http::fake(['*payment_pages' => Http::response(['url' => $url], 200)]);
    $payment = wpCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertStatus($status);
})->with([
    ['https://payments-test.worldpay.com/app/x', 200],
    ['https://evil.example.com/app/x', 502],
    ['http://hpp-sandbox.worldpay.com/app/x', 502],
]);

it('reuses the saved page with no Worldpay call', function () {
    wpFakeCreate();
    $payment = wpCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertOk();
    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertOk();

    expect(wpCreateCalls())->toBe(1)->and(WorldpayPage::count())->toBe(1);
});

it('refuses without consent, for another provider, when not pending or inactive, with no Worldpay call', function (callable $make) {
    Http::fake();
    $payment = $make();

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertStatus(422);
    Http::assertNothingSent();
})->with([
    'no consent' => [fn () => wpCheckoutPayment(consent: false)],
    'completed' => [fn () => wpCheckoutPayment(['status' => 'completed'])],
    'stripe payment' => [fn () => Payment::factory()->create()],
    'inactive account' => [function () {
        $payment = wpCheckoutPayment();
        $payment->worldpayAccount->update(['is_active' => false]);

        return $payment;
    }],
]);

it('answers 502 with a fixed message and an unusable row when Worldpay fails', function (int $status) {
    Http::fake(['*payment_pages' => Http::response(['errorName' => 'internalError', 'message' => 'secret text'], $status)]);
    Log::spy();
    $payment = wpCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")
        ->assertStatus(502)->assertExactJson(['message' => 'We could not start your payment. Please try again.']);

    $page = WorldpayPage::sole();
    expect($page->failed_at)->not->toBeNull()->and($page->url)->toBeNull()
        ->and($payment->fresh()->worldpay_charge_started_at)->toBeNull();
    Log::shouldHaveReceived('error')->once();
})->with([400, 500]);

it('never hands out or counts a failed page, and does not reuse its reference', function () {
    $payment = wpCheckoutPayment();
    WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'expires_at' => now()->addDay(), 'failed_at' => now()]);
    wpFakeCreate();

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertOk();

    expect(WorldpayPage::orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2]);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment_pages') && $r->data()['transactionReference'] === $payment->uuid.'-2');
});

it('makes a 409 inside the grace time and a new page after it when nothing was paid', function () {
    config(['services.worldpay.max_pages' => 2]);
    wpFakeCreate();
    $payment = wpCheckoutPayment();
    WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->subSeconds(30)]);
    $payment->forceFill(['worldpay_charge_started_at' => now()->subDay()])->save();

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")
        ->assertStatus(409)->assertJson(['message' => 'Your payment is being prepared. Please try again in a moment.']);
    expect(wpCreateCalls())->toBe(0);

    $this->travel(5)->minutes();
    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertOk();
    expect(WorldpayPage::count())->toBe(2)->and(WorldpayPage::where('sequence', 2)->exists())->toBeTrue();
});

it('completes instead of creating a page when the old page was paid, and blocks while a sale is only authorized', function () {
    config(['services.worldpay.max_pages' => 2]);
    $payment = wpCheckoutPayment();
    $page = WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->subHour()]);

    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => [[
        'timestamp' => '2026-11-17T13:45:51Z', 'paymentId' => 'payX', 'transactionReference' => $page->transaction_reference,
        'lastEvent' => 'authorized', 'value' => ['amount' => 1234, 'currency' => 'GBP'],
    ]]]], 200)]);

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")
        ->assertStatus(409)->assertJson(['message' => 'We are confirming your payment. Please do not pay again.']);
    expect(wpCreateCalls())->toBe(0);

    Cache::flush();
    Http::swap(new Factory);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => [[
        'timestamp' => '2026-11-17T13:45:51Z', 'paymentId' => 'payX', 'transactionReference' => $page->transaction_reference,
        'lastEvent' => 'settled', 'value' => ['amount' => 1234, 'currency' => 'GBP'],
    ]]]], 200)]);

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertStatus(409);
    expect($payment->fresh()->status)->toBe('completed')->and(WorldpayPage::count())->toBe(1);
});

it('refuses a page beyond max_pages with 422', function () {
    wpFakeCreate();
    $payment = wpCheckoutPayment();
    WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->subDay()]);

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")
        ->assertStatus(422)->assertJson(['message' => 'This payment link can no longer be used.']);
    expect(wpCreateCalls())->toBe(0);
});

it('replaces a refused page at once when the setting allows more pages', function () {
    config(['services.worldpay.max_pages' => 2]);
    wpFakeCreate();
    $payment = wpCheckoutPayment();
    WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->addDay(), 'last_event' => 'authorizationFailed']);

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertOk();
    expect(WorldpayPage::count())->toBe(2);
});

it('limits checkout calls per payment to ten an hour with a JSON 429', function () {
    wpFakeCreate();
    $payment = wpCheckoutPayment();

    foreach (range(1, 10) as $i) {
        $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertOk();
    }

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")
        ->assertStatus(429)->assertExactJson(['message' => 'Too many attempts. Please try again later.']);
    expect(wpCreateCalls())->toBe(1);
});

it('uses the app name when the brand name has nothing usable', function () {
    wpFakeCreate();
    $payment = wpCheckoutPayment();
    $payment->brand->update(['name' => '&&& /// \'\'\'']);

    $this->postJson("/pay/{$payment->uuid}/worldpay/checkout")->assertOk();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment_pages') && $r->data()['narrative']['line1'] !== '' && mb_strlen($r->data()['narrative']['line1']) <= 24);
});

// ---- Status (AC-10) ----

it('reports state only and runs at most one lookup per ten seconds', function () {
    $payment = wpCheckoutPayment();
    WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->addDay()]);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => []]], 200)]);

    $this->getJson("/pay/{$payment->uuid}/worldpay/status")->assertOk()->assertExactJson(['state' => 'waiting']);
    $this->getJson("/pay/{$payment->uuid}/worldpay/status")->assertOk()->assertExactJson(['state' => 'waiting']);

    Http::assertSentCount(1);
});

it('gives retry for a fresh refusal on a dead page, completed after a sale and unavailable when inactive', function () {
    $payment = wpCheckoutPayment();
    $page = WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->addDay(), 'last_event' => 'authorizationFailed']);
    WorldpayLookupAttempt::create(['payment_id' => $payment->id, 'page_id' => $page->id, 'source' => 'poll', 'outcome' => 'declined', 'last_event' => 'authorizationFailed', 'last_checked_at' => now()]);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => [[
        'timestamp' => '2026-11-17T13:45:51Z', 'paymentId' => 'payR', 'transactionReference' => $page->transaction_reference,
        'lastEvent' => 'authorizationFailed', 'value' => ['amount' => 1234, 'currency' => 'GBP'],
    ]]]], 200)]);

    $this->getJson("/pay/{$payment->uuid}/worldpay/status")->assertExactJson(['state' => 'retry']);

    $payment->worldpayAccount->update(['is_active' => false]);
    Cache::flush();
    $this->getJson("/pay/{$payment->uuid}/worldpay/status")->assertExactJson(['state' => 'unavailable']);

    $payment->worldpayAccount->update(['is_active' => true]);
    $payment->update(['status' => 'completed']);
    $this->getJson("/pay/{$payment->uuid}/worldpay/status")->assertExactJson(['state' => 'completed']);
});

it('404s the status of a payment that is not Worldpay', function () {
    $payment = Payment::factory()->create();

    $this->getJson("/pay/{$payment->uuid}/worldpay/status")->assertNotFound();
});

// ---- Pay page (AC-4, AC-5, AC-10) ----

it('renders the Worldpay pay page with no secret, url or reference in the props', function () {
    $payment = wpCheckoutPayment(consent: false);
    $page = WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a?cs=SECRETCS', 'expires_at' => now()->addDay()]);

    $response = $this->get("/pay/{$payment->uuid}?worldpay_return=failure");

    $response->assertInertia(fn (Assert $p) => $p
        ->component('ClientPayment/PayWorldpay')
        ->where('returnStatus', 'failure')
        ->where('hasConsent', false)
        ->where('payment.amount', 1234)
        ->where('payment.currency', 'gbp'));

    $response->assertDontSee('SECRETCS')->assertDontSee($page->transaction_reference.'"', false);
    $response->assertDontSee($payment->worldpayAccount->api_password);
});

it('ignores a return word that is not one of the six', function () {
    $payment = wpCheckoutPayment();

    $this->get("/pay/{$payment->uuid}?worldpay_return=hacked")->assertInertia(fn (Assert $p) => $p->where('returnStatus', null));
    $this->get("/pay/{$payment->uuid}")->assertInertia(fn (Assert $p) => $p->where('returnStatus', null));
});

it('looks up after the response on a success or pending return and never changes status from the word', function () {
    $payment = wpCheckoutPayment();
    WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->addDay()]);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => []]], 200)]);

    $this->get("/pay/{$payment->uuid}?worldpay_return=success")->assertOk();

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'worldpay.com'))->count())->toBe(1);
    expect($payment->fresh()->status)->toBe('pending');
});

it('redirects a completed payment to Success and shows Unavailable for an inactive account', function () {
    $done = wpCheckoutPayment(['status' => 'completed']);
    $this->get("/pay/{$done->uuid}")->assertRedirect(route('pay.success', $done));

    $inactive = wpCheckoutPayment();
    $inactive->worldpayAccount->update(['is_active' => false]);
    $this->get("/pay/{$inactive->uuid}")->assertInertia(fn (Assert $p) => $p->component('ClientPayment/Unavailable'));
});

it('does not change the content security policy on the Worldpay pay page', function () {
    $worldpay = wpCheckoutPayment();
    $other = Payment::factory()->kurv()->create();

    $a = $this->get("/pay/{$worldpay->uuid}")->headers->get('Content-Security-Policy');
    $b = $this->get("/pay/{$other->uuid}")->headers->get('Content-Security-Policy');

    expect($a)->not->toContain('worldpay');
    expect(preg_replace('/nonce-[A-Za-z0-9+\/=]+/', 'N', (string) $a))->toBe(preg_replace('/nonce-[A-Za-z0-9+\/=]+/', 'N', (string) $b));
});
