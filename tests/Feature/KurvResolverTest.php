<?php

use App\Jobs\ResolveKurvPayment;
use App\Jobs\SendPaymentNotification;
use App\Models\KurvAccount;
use App\Models\KurvLookupAttempt;
use App\Models\Payment;
use App\Services\Kurv\KurvAmount;
use App\Services\Kurv\KurvCallbackToken;
use App\Services\Kurv\KurvChargeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

function kurvPaymentWithLink(array $overrides = []): Payment
{
    return Payment::factory()->kurv()->create(array_merge([
        'amount' => 1234,
        'currency' => 'usd',
        'status' => 'pending',
        'kurv_request_id' => '1074096OO',
        'kurv_payment_url' => 'https://sandbox-pay.kurv.app/once_off/abc',
        'kurv_charge_started_at' => now()->subMinutes(5),
    ], $overrides));
}

/** @param array<string, mixed> $transaction */
function kurvRequestReply(array $transaction = []): array
{
    return ['transaction' => array_merge([
        'transaction_id' => '1074096OO',
        'status' => 'pending',
        'status_description' => '',
        'payment_id' => null,
        'amount' => 12.34,
        'currency' => 'USD',
        'payment_type' => 'DB',
        'payment_date' => null,
    ], $transaction)];
}

function kurvPaid(array $transaction = []): array
{
    return kurvRequestReply(array_merge([
        'status' => 'success',
        'payment_id' => 'dbljb8v0i47ai6vebgp0',
        'payment_date' => '2026-11-05T09:30:00.000Z',
    ], $transaction));
}

function fakeKurv(array $request, array $history = ['payments' => []]): void
{
    Http::fake([
        '*/payment-requests/*' => Http::response($request, 200),
        '*/payments*' => Http::response($history, 200),
    ]);
}

// ---- Amounts ----

it('converts cents to Kurv decimals and back with integer math', function () {
    expect(KurvAmount::centsToDecimal(1234))->toBe('12.34')
        ->and(KurvAmount::centsToDecimal(5))->toBe('0.05')
        ->and(KurvAmount::decimalToCents(12.34))->toBe(1234)
        ->and(KurvAmount::decimalToCents(0.1))->toBe(10)
        ->and(KurvAmount::decimalToCents('15'))->toBe(1500)
        ->and(KurvAmount::decimalToCents('1e3'))->toBeNull()
        ->and(KurvAmount::decimalToCents('-5'))->toBeNull()
        ->and(KurvAmount::decimalToCents('1.234'))->toBeNull();
});

// ---- Resolver (AC-7, AC-11) ----

it('completes a pending payment once Kurv reports a matching sale and notifies once', function () {
    Bus::fake([SendPaymentNotification::class]);
    fakeKurv(kurvPaid());
    $payment = kurvPaymentWithLink();

    $outcome = app(KurvChargeResolver::class)->resolve($payment, 'callback');
    app(KurvChargeResolver::class)->resolve($payment->fresh(), 'poll');

    $fresh = $payment->fresh();
    expect($outcome)->toBe('paid')
        ->and($fresh->status)->toBe('completed')
        ->and($fresh->kurv_payment_id)->toBe('dbljb8v0i47ai6vebgp0')
        ->and($fresh->paid_at->utc()->toIso8601String())->toBe('2026-11-05T09:30:00+00:00')
        ->and($fresh->kurv_last_lookup_at)->not->toBeNull();

    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
    expect(KurvLookupAttempt::where('payment_id', $payment->id)->first())
        ->outcome->toBe('paid')->source->toBe('callback')->kurv_request_id->toBe('1074096OO');
});

it('does not complete on a different amount, currency or payment type', function (array $transaction) {
    Bus::fake([SendPaymentNotification::class]);
    Log::spy();
    fakeKurv(kurvPaid($transaction));
    $payment = kurvPaymentWithLink();

    $outcome = app(KurvChargeResolver::class)->resolve($payment, 'poll');

    expect($outcome)->toBe('mismatch')->and($payment->fresh()->status)->toBe('pending');
    Bus::assertNotDispatched(SendPaymentNotification::class);
    Log::shouldHaveReceived('error')->once();
})->with([
    'amount' => [['amount' => 99.99]],
    'currency' => [['currency' => 'GBP']],
    'pre authorization' => [['payment_type' => 'PA']],
]);

it('ignores a success for another transaction id', function () {
    fakeKurv(kurvPaid(['transaction_id' => '1074000OO']));
    $payment = kurvPaymentWithLink();

    expect(app(KurvChargeResolver::class)->resolve($payment, 'poll'))->toBe('mismatch')
        ->and($payment->fresh()->status)->toBe('pending');
});

it('finds the payment id in the history when a paid request has none', function () {
    fakeKurv(kurvPaid(['payment_id' => null]), ['payments' => [
        ['transaction_id' => '1074000OO', 'payment_id' => 'older', 'status' => 'ACK', 'amount' => 12.34, 'currency' => 'USD', 'payment_type' => 'DB'],
        ['transaction_id' => '1074096OO', 'payment_id' => 'right_one', 'status' => 'ACK', 'amount' => 12.34, 'currency' => 'USD', 'payment_type' => 'DB', 'payment_date' => '2026-11-05T09:31:00.000Z'],
    ]]);
    $payment = kurvPaymentWithLink();

    app(KurvChargeResolver::class)->resolve($payment, 'poll');

    expect($payment->fresh()->kurv_payment_id)->toBe('right_one')->and($payment->fresh()->status)->toBe('completed');
});

it('leaves a pending request pending and records one attempt', function () {
    fakeKurv(kurvRequestReply());
    $payment = kurvPaymentWithLink();

    expect(app(KurvChargeResolver::class)->resolve($payment, 'sweep'))->toBe('pending');
    expect($payment->fresh()->status)->toBe('pending')
        ->and(KurvLookupAttempt::where('payment_id', $payment->id)->count())->toBe(1);
});

it('reports a decline once from the history and then judges a retry on its own', function () {
    $nok = fn (string $at) => ['transaction_id' => '1074096OO', 'payment_id' => '', 'status' => 'NOK', 'amount' => 12.34, 'currency' => 'USD', 'payment_type' => 'DB', 'result_description' => 'Card Declined (Do not honor)', 'payment_date' => $at];
    fakeKurv(kurvRequestReply(), ['payments' => [$nok(now()->subSeconds(30)->toIso8601String())]]);
    $payment = kurvPaymentWithLink();

    expect(app(KurvChargeResolver::class)->resolve($payment, 'poll'))->toBe('declined');
    expect(KurvLookupAttempt::latest('id')->first()->reason)->toBe('Card Declined (Do not honor)')
        ->and($payment->fresh()->status)->toBe('pending');

    // Same old failed row on the next lookup is not a new decline.
    expect(app(KurvChargeResolver::class)->resolve($payment->fresh(), 'poll'))->toBe('pending');
});

it('does not look for declines in the background sweep', function () {
    fakeKurv(kurvRequestReply());
    $payment = kurvPaymentWithLink();

    app(KurvChargeResolver::class)->resolve($payment, 'sweep');

    Http::assertSentCount(1);
});

it('sets the closed time once when Kurv reports a closed link', function () {
    fakeKurv(kurvRequestReply(['status' => 'expired']));
    $payment = kurvPaymentWithLink();

    expect(app(KurvChargeResolver::class)->resolve($payment, 'poll'))->toBe('closed');
    $closedAt = $payment->fresh()->kurv_link_closed_at;
    expect($closedAt)->not->toBeNull();

    app(KurvChargeResolver::class)->resolve($payment->fresh(), 'poll');
    expect($payment->fresh()->kurv_link_closed_at->equalTo($closedAt))->toBeTrue();
});

it('records an unknown status and never fails the payment', function () {
    fakeKurv(kurvRequestReply(['status' => 'something_new']));
    $payment = kurvPaymentWithLink();

    expect(app(KurvChargeResolver::class)->resolve($payment, 'poll'))->toBe('unknown')
        ->and($payment->fresh()->status)->toBe('pending');
});

it('records an error for a 404, 422, 429 or timeout and changes nothing', function (int $status) {
    Http::fake(['*' => Http::response(['is_valid' => false], $status)]);
    $payment = kurvPaymentWithLink();

    expect(app(KurvChargeResolver::class)->resolve($payment, 'poll'))->toBe('error')
        ->and($payment->fresh()->status)->toBe('pending');
})->with([404, 422, 429, 500]);

it('makes no call and writes no row without a request id', function () {
    Http::fake();
    $payment = kurvPaymentWithLink(['kurv_request_id' => null]);

    expect(app(KurvChargeResolver::class)->resolve($payment, 'poll'))->toBeNull();
    Http::assertNothingSent();
    expect(KurvLookupAttempt::count())->toBe(0);
});

it('runs for an inactive account', function () {
    fakeKurv(kurvPaid());
    $payment = kurvPaymentWithLink();
    $payment->kurvAccount->update(['is_active' => false]);

    expect(app(KurvChargeResolver::class)->resolve($payment, 'sweep'))->toBe('paid');
});

it('queues a recheck instead of a second lookup while one is running', function () {
    fakeKurv(kurvRequestReply());
    $payment = kurvPaymentWithLink();
    $lock = Cache::lock('kurv:lookup:'.$payment->id, 60);
    $lock->get();

    expect(app(KurvChargeResolver::class)->resolve($payment, 'callback'))->toBeNull();
    expect(Cache::has('kurv:recheck:'.$payment->id))->toBeTrue();
    Http::assertNothingSent();
});

it('logs a second payment for a completed payment and changes nothing', function () {
    Log::spy();
    fakeKurv(kurvPaid(), ['payments' => [
        ['transaction_id' => '1074096OO', 'payment_id' => 'first', 'status' => 'ACK', 'amount' => 12.34, 'currency' => 'USD', 'payment_type' => 'DB'],
        ['transaction_id' => '1074096OO', 'payment_id' => 'second', 'status' => 'ACK', 'amount' => 12.34, 'currency' => 'USD', 'payment_type' => 'DB'],
    ]]);
    $payment = kurvPaymentWithLink(['status' => 'completed', 'kurv_payment_id' => 'first', 'paid_at' => now()]);

    expect(app(KurvChargeResolver::class)->resolve($payment, 'callback'))->toBe('duplicate');
    expect($payment->fresh()->kurv_payment_id)->toBe('first');
    Log::shouldHaveReceived('error')->once();
});

it('does not look up a completed payment unless a callback arrives', function () {
    Http::fake();
    $payment = kurvPaymentWithLink(['status' => 'completed', 'kurv_payment_id' => 'first', 'paid_at' => now()]);

    expect(app(KurvChargeResolver::class)->resolve($payment, 'poll'))->toBeNull();
    Http::assertNothingSent();
});

it('does not complete a cancelled payment', function () {
    Bus::fake([SendPaymentNotification::class]);
    Log::spy();
    fakeKurv(kurvPaid());
    $payment = kurvPaymentWithLink(['status' => 'cancelled']);

    app(KurvChargeResolver::class)->resolve($payment, 'poll');

    expect($payment->fresh()->status)->toBe('cancelled');
    Bus::assertNotDispatched(SendPaymentNotification::class);
    Log::shouldHaveReceived('error')->once();
});

// ---- Callback (AC-8, AC-14) ----

it('answers 200 and queues one lookup for a good token, after the response', function () {
    Bus::fake();
    $payment = kurvPaymentWithLink();
    $url = KurvCallbackToken::urlFor($payment->kurvAccount, $payment);

    $this->postJson($url, ['status' => 'success'])->assertOk();

    Bus::assertDispatchedAfterResponse(ResolveKurvPayment::class, fn ($job) => $job->paymentId === $payment->id && $job->source === 'callback');
    expect($payment->fresh()->status)->toBe('pending');
});

it('answers 200 and does nothing for a bad token, unknown payment, wrong account or unknown account', function () {
    Bus::fake();
    Log::spy();
    $payment = kurvPaymentWithLink();
    $other = KurvAccount::factory()->create();
    $good = KurvCallbackToken::for($payment->uuid);

    $this->postJson("/webhook/kurv/{$payment->kurv_account_id}?payment={$payment->uuid}&token=wrong")->assertOk();
    $this->postJson("/webhook/kurv/{$payment->kurv_account_id}?payment=nope&token={$good}")->assertOk();
    $this->postJson("/webhook/kurv/{$other->id}?payment={$payment->uuid}&token={$good}")->assertOk();
    $this->postJson("/webhook/kurv/9999?payment={$payment->uuid}&token={$good}")->assertOk();
    $this->postJson("/webhook/kurv/abc?payment={$payment->uuid}&token={$good}")->assertOk();

    Bus::assertNotDispatched(ResolveKurvPayment::class);
    Log::shouldHaveReceived('warning')->times(5);
});

it('never lets the callback body change a status', function () {
    Bus::fake();
    $payment = kurvPaymentWithLink();

    $this->postJson(KurvCallbackToken::urlFor($payment->kurvAccount, $payment), [
        'status' => 'success', 'result' => 'ACK', 'amount' => 12.34, 'transaction_id' => '1074096OO',
    ])->assertOk();

    expect($payment->fresh()->status)->toBe('pending');
});

it('allows one callback lookup per payment every 10 seconds', function () {
    Bus::fake();
    $payment = kurvPaymentWithLink();
    $url = KurvCallbackToken::urlFor($payment->kurvAccount, $payment);

    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertOk();

    Bus::assertDispatchedTimes(ResolveKurvPayment::class, 1);
});

it('masks the callback token in log lines', function () {
    Log::spy();
    $payment = kurvPaymentWithLink();

    $this->postJson("/webhook/kurv/{$payment->kurv_account_id}?payment={$payment->uuid}&token=supersecrettokenvalue")->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['token'] ?? '') === 'supers...');
});

it('does not require CSRF on the callback route', function () {
    $payment = kurvPaymentWithLink();

    $this->post("/webhook/kurv/{$payment->kurv_account_id}?payment=x&token=y")->assertOk();
});
