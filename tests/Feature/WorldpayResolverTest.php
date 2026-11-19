<?php

use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Models\WorldpayLookupAttempt;
use App\Models\WorldpayPage;
use App\Services\Worldpay\WorldpayChargeResolver;
use App\Services\Worldpay\WorldpayStalePaymentSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function wpPayment(array $attributes = []): Payment
{
    return Payment::factory()->worldpay()->create($attributes + ['amount' => 5000, 'status' => 'pending']);
}

function wpPage(Payment $payment, int $sequence = 1, array $attributes = []): WorldpayPage
{
    return WorldpayPage::create($attributes + [
        'payment_id' => $payment->id,
        'sequence' => $sequence,
        'transaction_reference' => $payment->uuid.'-'.$sequence,
        'url' => 'https://hpp-sandbox.worldpay.com/app/hpp/x',
        'expires_at' => now()->addDay(),
    ]);
}

/** @return array<string, mixed> */
function wpRow(string $reference, string $event, int $amount = 5000, string $currency = 'GBP', string $id = 'payABC'): array
{
    return [
        'timestamp' => '2026-11-17T13:45:51.762Z', 'paymentId' => $id, 'transactionReference' => $reference,
        'lastEvent' => $event, 'entity' => 'PO1', 'value' => ['currency' => $currency, 'amount' => $amount],
    ];
}

function wpFake(array $rows): void
{
    Http::swap(new Factory);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => $rows]], 200)]);
}

beforeEach(function () {
    Bus::fake([SendPaymentNotification::class]);
});

it('completes the payment once for one paid sale with the exact amount in GBP', function () {
    $payment = wpPayment();
    $page = wpPage($payment);
    wpFake([wpRow($page->transaction_reference, 'sentForSettlement')]);

    $outcome = app(WorldpayChargeResolver::class)->resolve($payment, 'poll');

    $payment->refresh();
    expect($outcome)->toBe('paid')
        ->and($payment->status)->toBe('completed')
        ->and($payment->worldpay_payment_id)->toBe('payABC')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->worldpay_last_lookup_at)->not->toBeNull()
        ->and(WorldpayLookupAttempt::count())->toBe(1);
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);

    // A second run on the completed payment changes nothing and sends nothing more.
    app(WorldpayChargeResolver::class)->resolve($payment->fresh(), 'sweep');
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('does nothing and makes no call when the payment has no usable page', function () {
    Http::fake();
    $payment = wpPayment();
    wpPage($payment, 1, ['url' => null]);
    wpPage($payment, 2, ['failed_at' => now()]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBeNull();
    Http::assertNothingSent();
    expect(WorldpayLookupAttempt::count())->toBe(0);
});

it('calls it a mismatch when the amount or currency differs and changes nothing', function (int $amount, string $currency) {
    $payment = wpPayment();
    $page = wpPage($payment);
    wpFake([wpRow($page->transaction_reference, 'settled', $amount, $currency)]);
    Log::spy();

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('mismatch');
    expect($payment->fresh()->status)->toBe('pending')->and($payment->fresh()->worldpay_payment_id)->toBeNull();
    Log::shouldHaveReceived('error')->atLeast()->once();
})->with([[4999, 'GBP'], [5000, 'USD']]);

it('ignores a paid row whose reference is not one of the payment pages', function () {
    $payment = wpPayment();
    wpPage($payment);
    wpFake([wpRow('someone-else-1', 'settled')]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('pending');
    expect($payment->fresh()->status)->toBe('pending');
});

it('treats two paid rows as a duplicate and changes nothing', function () {
    $payment = wpPayment();
    $page = wpPage($payment);
    wpFake([
        wpRow($page->transaction_reference, 'settled', id: 'payONE'),
        wpRow($page->transaction_reference, 'sentForSettlement', id: 'payTWO'),
    ]);
    Log::spy();

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('duplicate');
    expect($payment->fresh()->status)->toBe('pending');
    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('only logs a second paid sale on an already completed payment', function () {
    $payment = wpPayment(['status' => 'completed', 'worldpay_payment_id' => 'payFIRST']);
    $first = wpPage($payment, 1);
    $second = wpPage($payment, 2);
    WorldpayLookupAttempt::create(['payment_id' => $payment->id, 'page_id' => $first->id, 'source' => 'poll', 'outcome' => 'paid', 'last_checked_at' => now()]);
    wpFake([wpRow($second->transaction_reference, 'settled', id: 'paySECOND')]);
    Log::spy();
    config(['services.worldpay.max_pages' => 3]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'sweep'))->toBe('duplicate');
    $payment->refresh();
    expect($payment->status)->toBe('completed')->and($payment->worldpay_payment_id)->toBe('payFIRST');
    Log::shouldHaveReceived('error')->atLeast()->once();
    Http::assertSentCount(1); // the page that paid is not looked up again
});

it('never completes a cancelled payment', function () {
    $payment = wpPayment(['status' => 'cancelled']);
    $page = wpPage($payment);
    wpFake([wpRow($page->transaction_reference, 'settled')]);
    Log::spy();

    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');

    expect($payment->fresh()->status)->toBe('cancelled');
    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('maps refusals, pending sales, unknown events and API errors without changing the status', function (string $event, string $expected) {
    $payment = wpPayment();
    $page = wpPage($payment);
    wpFake([wpRow($page->transaction_reference, $event)]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe($expected);
    expect($payment->fresh()->status)->toBe('pending');
})->with([
    ['authorizationFailed', 'declined'],
    ['refused', 'declined'],
    ['authorized', 'pending'],
    ['sentForAuthorization', 'pending'],
    ['refunded', 'unknown'],
    ['somethingNew', 'unknown'],
    ['settlementFailed', 'error'],
]);

it('treats an API failure as an error and keeps the status', function () {
    $payment = wpPayment();
    wpPage($payment);
    Http::fake(['*' => Http::response('nope', 500)]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('error');
    expect($payment->fresh()->status)->toBe('pending');
});

it('sets closed_at only from an expiry event, never from page age', function () {
    $payment = wpPayment();
    $aged = wpPage($payment, 1, ['expires_at' => now()->subHour()]);
    wpFake([]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('closed');
    expect($aged->fresh()->closed_at)->toBeNull();

    wpFake([wpRow($aged->transaction_reference, 'expired')]);
    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');
    $closedAt = $aged->fresh()->closed_at;
    expect($closedAt)->not->toBeNull();

    $this->travel(5)->minutes();
    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');
    expect($aged->fresh()->closed_at->equalTo($closedAt))->toBeTrue();
});

it('adds one to checks for a repeat of the same outcome instead of a new row', function () {
    $payment = wpPayment();
    $page = wpPage($payment);
    wpFake([wpRow($page->transaction_reference, 'authorizationFailed')]);

    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');
    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');

    $row = WorldpayLookupAttempt::sole();
    expect($row->checks)->toBe(2)->and($row->last_event)->toBe('authorizationFailed');
});

it('sets the recheck key and skips when another lookup holds the lock', function () {
    $payment = wpPayment();
    wpPage($payment);
    Http::fake();
    $held = Cache::lock('worldpay:lookup:'.$payment->id, 30);
    $held->get();

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBeNull();
    expect(Cache::has('worldpay:recheck:'.$payment->id))->toBeTrue();
    Http::assertNothingSent();

    $held->release();
});

it('sweeps pending payments in the age window, skips fresh ones and completes on an inactive account', function () {
    $payment = wpPayment();
    $payment->worldpayAccount->update(['is_active' => false]);
    $page = wpPage($payment);
    $page->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $fresh = wpPayment();
    wpPage($fresh); // created just now, under min_age_seconds

    $recent = wpPayment(['worldpay_last_lookup_at' => now()->subMinute()]);
    wpPage($recent)->forceFill(['created_at' => now()->subMinutes(10)])->save();

    wpFake([wpRow($page->transaction_reference, 'settled')]);

    (new WorldpayStalePaymentSweep)();

    expect($payment->fresh()->status)->toBe('completed')
        ->and($fresh->fresh()->worldpay_last_lookup_at)->toBeNull()
        ->and($recent->fresh()->status)->toBe('pending');
    Http::assertSentCount(1);
});
