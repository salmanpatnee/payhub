<?php

use App\Models\KurvLookupAttempt;
use App\Models\Payment;
use App\Services\Kurv\KurvCallbackToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    foreach (['admin', 'agent', 'account'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function kurvCheckoutPayment(array $overrides = [], bool $consent = true): Payment
{
    $payment = Payment::factory()->kurv()->create(array_merge([
        'amount' => 1234,
        'currency' => 'usd',
        'status' => 'pending',
        'client_name' => 'Alice Smith',
        'client_email' => 'alice@example.com',
    ], $overrides));

    if ($consent) {
        $payment->consents()->create([
            'policy_versions' => ['terms' => '1'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'accepted_at' => now(),
        ]);
    }

    return $payment;
}

function fakeKurvCreate(): void
{
    Http::fake(['*/payment-requests' => Http::response([
        'result' => 'success',
        'transaction_id' => '1074200OO',
        'long_url' => 'https://sandbox-pay.kurv.app/once_off/abc',
        'short_url' => 'https://krpy.me/abc',
    ], 200)]);
}

// ---- Checkout (AC-5, AC-6) ----

it('creates one Kurv request from the saved amount and returns only the url', function () {
    fakeKurvCreate();
    $payment = kurvCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/kurv/checkout", ['amount' => 1, 'currency' => 'gbp', 'account_id' => 99])
        ->assertOk()
        ->assertExactJson(['url' => 'https://sandbox-pay.kurv.app/once_off/abc']);

    $fresh = $payment->fresh();
    expect($fresh->kurv_request_id)->toBe('1074200OO')
        ->and($fresh->kurv_payment_url)->toBe('https://sandbox-pay.kurv.app/once_off/abc')
        ->and($fresh->kurv_charge_started_at)->not->toBeNull();

    Http::assertSent(function ($request) use ($payment) {
        $body = $request->data();

        return $request->url() === 'https://api-sandbox.kurv.app/payment-requests'
            && $body['amount'] === 12.34
            && $body['currency'] === 'USD'
            && $body['customer_first_name'] === 'Alice Smith'
            && $body['email'] === 'alice@example.com'
            && $body['reference_number'] === $payment->formattedReferenceCode()
            && $body['response_url'] === KurvCallbackToken::urlFor($payment->kurvAccount, $payment)
            && $body['redirect_url'] === route('pay.show', $payment).'?kurv_return=1'
            && $body['cancel_url'] === route('pay.show', $payment)
            && $body['request_methods'] === ['WEB'];
    });
});

it('reuses the saved link and makes no second Kurv call', function () {
    fakeKurvCreate();
    $payment = kurvCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertOk();
    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertOk()
        ->assertExactJson(['url' => 'https://sandbox-pay.kurv.app/once_off/abc']);

    Http::assertSentCount(1);
});

it('answers 422 and makes no call when the payment cannot be paid', function (string $case) {
    Http::fake();
    $payment = match ($case) {
        'no consent' => kurvCheckoutPayment([], consent: false),
        'completed' => kurvCheckoutPayment(['status' => 'completed']),
        'cancelled' => kurvCheckoutPayment(['status' => 'cancelled']),
        'closed link' => kurvCheckoutPayment(['kurv_link_closed_at' => now()]),
        'inactive account' => tap(kurvCheckoutPayment(), fn (Payment $p) => $p->kurvAccount->update(['is_active' => false])),
        'wrong provider' => tap(kurvCheckoutPayment(), fn (Payment $p) => $p->forceFill(['provider' => 'stripe'])->save()),
    };

    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertStatus(422);

    Http::assertNothingSent();
    expect($payment->fresh()->kurv_request_id)->toBeNull();
})->with(['no consent', 'completed', 'cancelled', 'closed link', 'inactive account', 'wrong provider']);

it('answers 502 and saves nothing when Kurv fails', function (int $status) {
    Log::spy();
    Http::fake(['*' => Http::response(['is_valid' => false], $status)]);
    $payment = kurvCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertStatus(502)->assertJson(['message' => 'failed']);

    $fresh = $payment->fresh();
    expect($fresh->kurv_request_id)->toBeNull()->and($fresh->kurv_payment_url)->toBeNull()->and($fresh->kurv_charge_started_at)->toBeNull();
    Log::shouldHaveReceived('error')->once();
})->with([400, 401, 422, 500]);

it('answers 502 when Kurv sends an unreadable reply', function () {
    Http::fake(['*' => Http::response(['result' => 'failed'], 200)]);
    $payment = kurvCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertStatus(502);

    expect($payment->fresh()->kurv_request_id)->toBeNull();
});

it('retries a 429 once and then succeeds', function () {
    Http::fake(['*/payment-requests' => Http::sequence()
        ->push([], 429)
        ->push(['result' => 'success', 'transaction_id' => '1074201OO', 'long_url' => 'https://sandbox-pay.kurv.app/once_off/x'], 200)]);
    $payment = kurvCheckoutPayment();

    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertOk();

    Http::assertSentCount(2);
    expect($payment->fresh()->kurv_request_id)->toBe('1074201OO');
});

it('answers 409 when it cannot get the checkout lock', function () {
    Http::fake();
    $payment = kurvCheckoutPayment();
    $lock = Cache::lock('kurv:checkout:'.$payment->id, 60);
    $lock->get();

    // block(5) would wait 5 seconds; a very short wait keeps the test fast without changing the code path.
    config(['services.kurv.lock_seconds' => 60]);
    $started = microtime(true);
    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertStatus(409);

    expect(microtime(true) - $started)->toBeGreaterThan(4);
    Http::assertNothingSent();
})->group('slow');

it('limits checkout calls to 10 per payment an hour with a JSON 429 and no Kurv call', function () {
    fakeKurvCreate();
    $payment = kurvCheckoutPayment();

    foreach (range(1, 10) as $i) {
        $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertOk();
    }

    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")
        ->assertStatus(429)
        ->assertExactJson(['message' => 'Too many attempts. Please try again later.']);

    Http::assertSentCount(1);
});

// ---- Status poll (AC-9) ----

function kurvPolled(Payment $payment): array
{
    return test()->getJson("/pay/{$payment->uuid}/kurv/status")->assertOk()->json();
}

it('returns the state only and completes through the resolver', function () {
    Http::fake(['*/payment-requests/*' => Http::response(['transaction' => [
        'transaction_id' => '1074096OO', 'status' => 'success', 'payment_id' => 'p1',
        'amount' => 12.34, 'currency' => 'USD', 'payment_type' => 'DB', 'payment_date' => '2026-11-05T09:30:00.000Z',
    ]], 200)]);
    $payment = kurvCheckoutPayment(['kurv_request_id' => '1074096OO', 'kurv_charge_started_at' => now()->subMinutes(5)]);

    expect(kurvPolled($payment))->toBe(['state' => 'completed']);
    expect($payment->fresh()->status)->toBe('completed');
});

it('runs at most one lookup every 10 seconds per payment', function () {
    Http::fake(['*/payment-requests/*' => Http::response(['transaction' => ['transaction_id' => '1074096OO', 'status' => 'pending']], 200),
        '*/payments*' => Http::response(['payments' => []], 200)]);
    $payment = kurvCheckoutPayment(['kurv_request_id' => '1074096OO', 'kurv_charge_started_at' => now()]);

    expect(kurvPolled($payment))->toBe(['state' => 'waiting']);
    kurvPolled($payment);
    kurvPolled($payment);

    expect(KurvLookupAttempt::where('payment_id', $payment->id)->count())->toBe(1);
});

it('returns retry after a fresh decline and waiting once it is older than a minute', function () {
    Http::fake();
    $payment = kurvCheckoutPayment(['kurv_request_id' => '1074096OO', 'kurv_charge_started_at' => now()]);
    Cache::put('kurv:poll:'.$payment->id, 1, 60);
    $attempt = KurvLookupAttempt::create(['payment_id' => $payment->id, 'source' => 'callback', 'outcome' => 'declined', 'kurv_request_id' => '1074096OO']);

    expect(kurvPolled($payment))->toBe(['state' => 'retry']);

    $attempt->forceFill(['created_at' => now()->subMinutes(5)])->save();
    expect(kurvPolled($payment))->toBe(['state' => 'waiting']);
});

it('returns unavailable for a closed link, an inactive account or a cancelled payment', function (string $case) {
    Http::fake();
    $payment = kurvCheckoutPayment(['kurv_request_id' => '1074096OO']);

    match ($case) {
        'closed' => $payment->update(['kurv_link_closed_at' => now()]),
        'inactive' => $payment->kurvAccount->update(['is_active' => false]),
        'cancelled' => $payment->update(['status' => 'cancelled']),
    };

    expect(kurvPolled($payment->fresh()))->toBe(['state' => 'unavailable']);
})->with(['closed', 'inactive', 'cancelled']);

it('makes no lookup for a payment without a request id and 404s for another provider', function () {
    Http::fake();
    $payment = kurvCheckoutPayment();

    expect(kurvPolled($payment))->toBe(['state' => 'waiting']);
    Http::assertNothingSent();

    $payment->forceFill(['provider' => 'stripe'])->save();
    $this->getJson("/pay/{$payment->uuid}/kurv/status")->assertNotFound();
});

it('throttles the status endpoint to 60 a minute', function () {
    Http::fake();
    $payment = kurvCheckoutPayment();

    foreach (range(1, 60) as $i) {
        $this->getJson("/pay/{$payment->uuid}/kurv/status")->assertOk();
    }

    $this->getJson("/pay/{$payment->uuid}/kurv/status")->assertStatus(429);
});

// ---- Pay page (AC-3, AC-4, AC-9) ----

it('renders the Kurv pay page with safe props only', function () {
    $payment = kurvCheckoutPayment(['kurv_request_id' => '1074096OO', 'kurv_payment_url' => 'https://sandbox-pay.kurv.app/once_off/secret'], consent: false);

    $this->get("/pay/{$payment->uuid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ClientPayment/PayKurv')
            ->where('payment.amount', 1234)
            ->where('payment.currency', 'usd')
            ->where('hasConsent', false)
            ->where('hasLink', true)
            ->where('returnStatus', null)
            ->missing('kurvAccount')
            ->missing('payment.kurv_payment_url')
            ->missing('payment.kurv_request_id'));

    expect(json_encode($this->get("/pay/{$payment->uuid}")->viewData('page')))->not->toContain('secret');
});

it('passes returned only for kurv_return=1 and ignores other query values', function (string $query, ?string $expected) {
    $payment = kurvCheckoutPayment();

    $this->get("/pay/{$payment->uuid}{$query}")
        ->assertInertia(fn (Assert $page) => $page->where('returnStatus', $expected));
})->with([
    'flag' => ['?kurv_return=1', 'returned'],
    'flag and extras' => ['?kurv_return=1&status=success&x=1', 'returned'],
    'other value' => ['?kurv_return=2', null],
    'extras only' => ['?status=success', null],
]);

it('redirects a completed payment to Success before the unavailable guard', function () {
    $payment = kurvCheckoutPayment(['status' => 'completed', 'paid_at' => now()]);

    $this->get("/pay/{$payment->uuid}")->assertRedirect(route('pay.success', $payment));
});

it('renders Unavailable for a closed link, an inactive account and a cancelled payment, never Stripe', function (string $case) {
    $payment = kurvCheckoutPayment(['kurv_request_id' => '1074096OO']);

    match ($case) {
        'closed' => $payment->update(['kurv_link_closed_at' => now()]),
        'inactive' => $payment->kurvAccount->update(['is_active' => false]),
        'cancelled' => $payment->update(['status' => 'cancelled']),
    };

    $this->get("/pay/{$payment->uuid}")
        ->assertInertia(fn (Assert $page) => $page->component('ClientPayment/Unavailable')->where('provider', 'kurv'));
})->with(['closed', 'inactive', 'cancelled']);

it('records consent for a Kurv payment through the shared consent endpoint', function () {
    $payment = kurvCheckoutPayment(consent: false);

    $this->postJson("/pay/{$payment->uuid}/consent", ['accepted' => true])->assertOk();

    expect($payment->consents()->count())->toBe(1);
});

it('adds no Content Security Policy host for the Kurv pay page', function () {
    $payment = kurvCheckoutPayment();
    $other = Payment::factory()->create();

    $kurv = $this->get("/pay/{$payment->uuid}")->headers->get('Content-Security-Policy');
    $stripe = $this->get("/pay/{$other->uuid}")->headers->get('Content-Security-Policy');

    expect($kurv)->not->toContain('kurv.app')->and($kurv)->toBe($stripe);
});
