<?php

use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Models\WorldpayAccount;
use App\Models\WorldpayChargeAttempt;
use App\Models\WorldpayLookupAttempt;
use App\Services\Worldpay\WorldpayChargeResolver;
use App\Services\Worldpay\WorldpayStalePaymentSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function wcPayment(array $attributes = []): Payment
{
    return Payment::factory()->worldpay()->create($attributes + ['amount' => 5000, 'status' => 'pending']);
}

function wcAttempt(Payment $payment, int $sequence = 1, array $attributes = []): WorldpayChargeAttempt
{
    return WorldpayChargeAttempt::factory()->create($attributes + [
        'payment_id' => $payment->id,
        'sequence' => $sequence,
        'transaction_reference' => $payment->uuid.'-c'.$sequence,
    ]);
}

/** @return array<string, mixed> */
function wcRow(string $reference, string $event, int $amount = 5000, string $currency = 'GBP', string $id = 'payABC'): array
{
    return [
        'timestamp' => '2026-11-17T13:45:51.762Z', 'paymentId' => $id, 'transactionReference' => $reference,
        'lastEvent' => $event, 'entity' => 'PO1', 'value' => ['currency' => $currency, 'amount' => $amount],
    ];
}

function wcFake(array $rows): void
{
    Http::swap(new Factory);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => $rows]], 200)]);
}

beforeEach(function () {
    Bus::fake([SendPaymentNotification::class]);
    Cache::flush();
});

it('has the card fields account defaults and a hidden encrypted three ds link', function () {
    $account = WorldpayAccount::factory()->create();
    expect($account->fresh()->mode)->toBe('hosted')->and($account->isCardFields())->toBeFalse();
    expect(WorldpayAccount::factory()->cardFields()->create()->isCardFields())->toBeTrue();

    $attempt = wcAttempt(wcPayment());
    $attempt->three_ds_state = 'https://example.test/next';
    $attempt->save();

    expect($attempt->fresh()->toArray())->not->toHaveKey('three_ds_state');
    expect(DB::table('worldpay_charge_attempts')->value('three_ds_state'))->not->toContain('example.test');
});

it('completes a card payment only from a paid row, and writes the attempt forward', function () {
    $payment = wcPayment();
    $attempt = wcAttempt($payment, 1, ['outcome' => 'unclear']);
    wcFake([wcRow($attempt->transaction_reference, 'sentForSettlement')]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('paid');

    $payment->refresh();
    expect($payment->status)->toBe('completed')->and($payment->worldpay_payment_id)->toBe('payABC');
    expect($attempt->fresh()->outcome)->toBe('approved')->and($attempt->fresh()->worldpay_payment_id)->toBe('payABC');
    expect(WorldpayLookupAttempt::first()->charge_attempt_id)->toBe($attempt->id);
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('makes no call and no change for a payment without pages or attempts', function () {
    Http::fake();
    $payment = wcPayment();
    // Event rows have no reference and are never looked up.
    WorldpayChargeAttempt::factory()->create(['payment_id' => $payment->id, 'sequence' => null, 'transaction_reference' => null, 'step' => 'event', 'outcome' => 'error']);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBeNull();
    Http::assertNothingSent();
});

it('calls it a mismatch for a wrong amount or currency on a card attempt', function (int $amount, string $currency) {
    $payment = wcPayment();
    $attempt = wcAttempt($payment);
    wcFake([wcRow($attempt->transaction_reference, 'settled', $amount, $currency)]);
    Log::spy();

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('mismatch');
    expect($payment->fresh()->status)->toBe('pending')->and($payment->fresh()->worldpay_payment_id)->toBeNull();
})->with([[4999, 'GBP'], [5000, 'USD']]);

it('ignores a paid row with a reference that belongs to no attempt of this payment', function () {
    $payment = wcPayment();
    wcAttempt($payment);
    wcFake([wcRow('someone-else-c1', 'settled')]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('pending');
    expect($payment->fresh()->status)->toBe('pending');
});

it('treats two paid card attempts as a duplicate, changes nothing and alerts once', function () {
    $payment = wcPayment();
    $one = wcAttempt($payment, 1);
    $two = wcAttempt($payment, 2);
    wcFake([
        wcRow($one->transaction_reference, 'settled', id: 'payONE'),
        wcRow($two->transaction_reference, 'settled', id: 'payTWO'),
    ]);
    Log::spy();

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('duplicate');
    expect($payment->fresh()->status)->toBe('pending');
    expect(Cache::has('worldpay:alert:duplicate:'.$payment->id))->toBeTrue();
    expect(WorldpayLookupAttempt::where('outcome', 'duplicate')->count())->toBe(2);
});

it('moves an attempt to refused once Worldpay shows a refusal, and never backward', function () {
    $payment = wcPayment();
    $unclear = wcAttempt($payment, 1, ['outcome' => 'unclear']);
    $refused = wcAttempt($payment, 2, ['outcome' => 'refused']);
    wcFake([wcRow($unclear->transaction_reference, 'refused')]);

    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');

    expect($unclear->fresh()->outcome)->toBe('refused');
    // A refused attempt is final: it is not looked up by a poll.
    Http::assertSentCount(1);
    expect($refused->fresh()->outcome)->toBe('refused');
});

it('lifts the block with not_found only after enough lookups over enough minutes', function () {
    $payment = wcPayment();
    $attempt = wcAttempt($payment, 1, ['outcome' => 'unclear']);
    wcFake([]);
    $resolver = app(WorldpayChargeResolver::class);

    // Too young: lookups alone are not enough.
    foreach (range(1, 4) as $i) {
        $resolver->resolve($payment->fresh(), 'poll');
    }
    expect($attempt->fresh()->outcome)->toBe('unclear');

    // Old enough, but the lookup count includes this one: 4 earlier checks are on the row.
    $attempt->forceFill(['created_at' => now()->subMinutes(11)])->save();
    $resolver->resolve($payment->fresh(), 'poll');
    expect($attempt->fresh()->outcome)->toBe('not_found');
});

it('does not lift the block before the third lookup even when the attempt is old', function () {
    $payment = wcPayment();
    $attempt = wcAttempt($payment, 1, ['outcome' => 'unclear', 'created_at' => now()->subMinutes(30)]);
    wcFake([]);

    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');
    expect($attempt->fresh()->outcome)->toBe('unclear');
    app(WorldpayChargeResolver::class)->resolve($payment->fresh(), 'poll');
    expect($attempt->fresh()->outcome)->toBe('unclear');
    app(WorldpayChargeResolver::class)->resolve($payment->fresh(), 'poll');
    expect($attempt->fresh()->outcome)->toBe('not_found');
});

it('lets a paid row win over not_found and catches a late paid row on a not_found attempt from the sweep', function () {
    $payment = wcPayment();
    $attempt = wcAttempt($payment, 1, ['outcome' => 'not_found', 'created_at' => now()->subHours(61)]);
    wcFake([wcRow($attempt->transaction_reference, 'sentForSettlement')]);

    // A poll never looks up a final-ish not_found attempt.
    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBeNull();

    // The sweep does, near the end of its window.
    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'sweep'))->toBe('paid');
    expect($payment->fresh()->status)->toBe('completed');
    expect($attempt->fresh()->outcome)->toBe('approved');
});

it('looks up a final attempt only once from the sweep', function () {
    $payment = wcPayment();
    wcAttempt($payment, 1, ['outcome' => 'refused', 'created_at' => now()->subHours(61)]);
    wcFake([]);

    app(WorldpayChargeResolver::class)->resolve($payment, 'sweep');
    expect(app(WorldpayChargeResolver::class)->resolve($payment->fresh(), 'sweep'))->toBeNull();
    Http::assertSentCount(1);
});

it('alerts once when an approved attempt is still not settled after the stuck limit', function () {
    $payment = wcPayment();
    $attempt = wcAttempt($payment, 1, ['outcome' => 'approved', 'created_at' => now()->subHours(25)]);
    wcFake([wcRow($attempt->transaction_reference, 'authorized')]);

    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');

    expect(Cache::has('worldpay:alert:stuck:'.$attempt->id))->toBeTrue();
    expect($payment->fresh()->status)->toBe('pending');
});

it('never throws on an unknown event for a card attempt', function () {
    $payment = wcPayment();
    $attempt = wcAttempt($payment);
    wcFake([wcRow($attempt->transaction_reference, 'somethingNew')]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('unknown');
    expect($payment->fresh()->status)->toBe('pending');
});

it('sweeps only card payments with an attempt still being decided and makes no charge call', function () {
    $due = wcPayment();
    wcAttempt($due, 1, ['outcome' => 'unclear', 'created_at' => now()->subMinutes(30)]);
    $tooNew = wcPayment();
    wcAttempt($tooNew, 1, ['outcome' => 'unclear']);
    $finalRecent = wcPayment();
    wcAttempt($finalRecent, 1, ['outcome' => 'refused', 'created_at' => now()->subMinutes(30)]);
    $tooOld = wcPayment();
    wcAttempt($tooOld, 1, ['outcome' => 'unclear', 'created_at' => now()->subHours(100)]);

    wcFake([]);
    (new WorldpayStalePaymentSweep)();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), $due->uuid));
    expect(WorldpayLookupAttempt::where('payment_id', $due->id)->count())->toBe(1);
});

it('counts settlementRequestSubmitted as paid for a hosted page', function () {
    $payment = wcPayment();
    $page = App\Models\WorldpayPage::create([
        'payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1',
        'url' => 'https://hpp-sandbox.worldpay.com/x', 'expires_at' => now()->addDay(),
    ]);
    wcFake([wcRow($page->transaction_reference, 'settlementRequestSubmitted')]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'poll'))->toBe('paid');
    expect($payment->fresh()->status)->toBe('completed');
});

function wcHostedPage(Payment $payment): App\Models\WorldpayPage
{
    return App\Models\WorldpayPage::create([
        'payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1',
        'url' => 'https://hpp-sandbox.worldpay.com/x', 'expires_at' => now()->addDay(),
    ]);
}

it('shows authorizationRefused as declined and leaves the payment pending', function () {
    $payment = wcPayment();
    $page = wcHostedPage($payment);
    wcFake([wcRow($page->transaction_reference, 'authorizationRefused')]);

    expect(app(WorldpayChargeResolver::class)->resolve($payment, 'return'))->toBe('declined');
    expect($payment->fresh()->status)->toBe('pending');
    expect(WorldpayLookupAttempt::first()->outcome)->toBe('declined');
});

it('keeps the hosted page alive after a refusal so the client can try again', function () {
    $payment = wcPayment();
    $page = wcHostedPage($payment);
    $page->update(['last_event' => 'authorizationRefused']);

    expect($page->fresh()->isDead())->toBeFalse();

    config(['services.worldpay.reuse_after_decline' => false]);
    expect($page->fresh()->isDead())->toBeTrue();
});

it('completes the payment when a retry on the same page succeeds after a refusal', function () {
    $payment = wcPayment();
    $page = wcHostedPage($payment);
    $resolver = app(WorldpayChargeResolver::class);

    wcFake([wcRow($page->transaction_reference, 'authorizationRefused', id: 'payREFUSED')]);
    expect($resolver->resolve($payment, 'return'))->toBe('declined');

    wcFake([
        wcRow($page->transaction_reference, 'authorizationRefused', id: 'payREFUSED'),
        array_merge(wcRow($page->transaction_reference, 'settlementRequestSubmitted', id: 'payOK'), ['timestamp' => '2026-11-17T13:50:00.000Z']),
    ]);
    expect($resolver->resolve($payment->fresh(), 'poll'))->toBe('paid');
    expect($payment->fresh()->status)->toBe('completed')->and($payment->fresh()->worldpay_payment_id)->toBe('payOK');
});

it('saves paid_at in the app timezone, not in the UTC time Worldpay sends', function () {
    config(['app.timezone' => 'Asia/Karachi']);
    $payment = wcPayment();
    $attempt = wcAttempt($payment);
    wcFake([wcRow($attempt->transaction_reference, 'sentForSettlement')]); // 13:45:51 UTC

    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');

    expect(DB::table('payments')->where('id', $payment->id)->value('paid_at'))->toStartWith('2026-11-17 18:45:51');
});
