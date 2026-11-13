<?php

use App\Jobs\ResolveNmiCharge;
use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Services\CardLock\CardLockGuard;
use App\Services\Nmi\NmiAmount;
use App\Services\Nmi\NmiChargeResolver;
use App\Services\Nmi\NmiQueryReply;
use App\Services\Nmi\NmiSaleReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake([SendPaymentNotification::class]);
});

function nmiPayment(array $attributes = [], array $account = []): Payment
{
    $payment = Payment::factory()->nmi()->create(array_merge([
        'amount' => 1234,
        'currency' => 'usd',
        'status' => 'pending',
        'client_name' => 'Jane Doe',
    ], $attributes));

    if ($account !== []) {
        $payment->nmiAccount->forceFill($account)->save();
    }

    $payment->consents()->create([
        'policy_versions' => [], 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'accepted_at' => now(),
    ]);

    return $payment->fresh();
}

/** One <transaction> block as query.php returns it. */
function nmiXmlTransaction(string $id, string $order, string $condition = 'pendingsettlement', string $amount = '12.34', string $currency = 'USD', bool $success = true, string $type = 'sale'): string
{
    $ok = $success ? '1' : '0';
    $code = $success ? '100' : '200';

    return "<transaction><transaction_id>{$id}</transaction_id><condition>{$condition}</condition><order_id>{$order}</order_id>"
        ."<currency>{$currency}</currency><avs_response>Y</avs_response><csc_response>M</csc_response>"
        ."<action><amount>{$amount}</amount><action_type>{$type}</action_type><success>{$ok}</success><response_code>{$code}</response_code></action></transaction>";
}

function nmiXml(string ...$transactions): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><nm_response>'.implode('', $transactions).'</nm_response>';
}

/**
 * Fake NMI. `$sale` is the v5 sale reply (array body, status), or a closure that throws.
 *
 * @param  array{0: array<string, mixed>, 1?: int}|Closure  $sale
 */
function fakeNmi(array|Closure $sale, ?string $query = null): void
{
    Http::fake([
        'sandbox.nmi.com/api/v5/payments/sale' => $sale instanceof Closure
            ? $sale
            : Http::response($sale[0], $sale[1] ?? 200),
        'sandbox.nmi.com/api/query.php' => Http::response($query ?? nmiXml(), 200),
    ]);
}

function nmiApproved(string $id = '9001'): array
{
    return [['object' => 'transaction', 'id' => $id, 'response_code' => '100', 'status' => 'pendingsettlement', 'avs_response' => 'Y', 'cvv_response' => 'M']];
}

function chargeNmi(Payment $payment, array $data = []): TestResponse
{
    return test()->postJson("/pay/{$payment->uuid}/nmi/charge", array_merge([
        'payment_token' => '00000000-000000-000000-000000000000',
        'method' => 'card',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
    ], $data));
}

it('completes the payment only after reading the sale back, and sends one notification', function () {
    $payment = nmiPayment();
    // The lookup answers with whatever order id the charge sent, so build it from the sent request.
    Http::fake([
        'sandbox.nmi.com/api/v5/payments/sale' => Http::response(nmiApproved()[0]),
        'sandbox.nmi.com/api/query.php' => function (Request $request) use ($payment) {
            $order = Payment::find($payment->id)->nmi_order_id;

            return Http::response(nmiXml(nmiXmlTransaction('9001', $order)));
        },
    ]);

    chargeNmi($payment)->assertOk()->assertJson(['outcome' => 'approved']);

    $payment->refresh();
    expect($payment->status)->toBe('completed')
        ->and($payment->nmi_transaction_id)->toBe('9001')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->nmiChargeAttempts()->first()->status)->toBe('approved');
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
    expect(Cache::has("nmi:pause:{$payment->id}"))->toBeFalse();
});

it('sends the amount and order id from the record and never the browser values', function () {
    $payment = nmiPayment();
    fakeNmi([['object' => 'transaction', 'id' => '9002', 'response_code' => '200', 'avs_response' => '', 'cvv_response' => 'M']]);

    chargeNmi($payment, ['amount' => 1, 'currency' => 'gbp', 'order_id' => 'evil'])->assertOk();

    Http::assertSent(function (Request $request) use ($payment) {
        if (! str_contains($request->url(), '/api/v5/payments/sale')) {
            return false;
        }
        $body = $request->data();

        return $body['amount'] === '12.34'
            && $body['currency'] === 'USD'
            && $body['order_details']['id'] === $payment->fresh()->nmi_order_id
            && $body['billing_address']['first_name'] === 'Jane'
            && ! isset($body['order_id'])
            && $request->hasHeader('Authorization', 'placeholder_private_key_for_dev_only');
    });
});

it('treats a decline as pending with a fixed message and an attempt row, and clears the pause', function () {
    $payment = nmiPayment();
    fakeNmi([['object' => 'transaction', 'id' => '9003', 'response_code' => '200', 'response_text' => 'DECLINE', 'status' => 'failed']]);

    chargeNmi($payment)->assertOk()->assertJson([
        'outcome' => 'declined',
        'message' => 'Your card was declined. Please try a different card.',
    ]);

    $payment->refresh();
    expect($payment->status)->toBe('pending')
        ->and($payment->nmiChargeAttempts()->first()->status)->toBe('declined')
        ->and($payment->nmiChargeAttempts()->first()->transaction_id)->toBe('9003')
        ->and(Cache::has("nmi:pause:{$payment->id}"))->toBeFalse();
});

it('maps card detail codes to check_details', function () {
    $payment = nmiPayment();
    fakeNmi([['id' => '9004', 'response_code' => '225']]);

    chargeNmi($payment)->assertOk()->assertJson(['outcome' => 'check_details']);
});

it('treats a duplicate sale as unclear, keeps the pause and queues a lookup', function () {
    Queue::fake();
    $payment = nmiPayment();
    fakeNmi([['type' => 'inputError', 'error_code' => 'E_DUPLICATE', 'message' => 'Duplicate transaction'], 400]);

    chargeNmi($payment)->assertOk()->assertJson(['outcome' => 'unclear']);

    expect(Cache::has("nmi:pause:{$payment->id}"))->toBeTrue();
    Queue::assertPushed(ResolveNmiCharge::class);
    chargeNmi($payment)->assertStatus(409)->assertJson(['outcome' => 'unclear']);
});

it('sets the pause before the sale call so a timed out sale cannot be sent twice', function () {
    Queue::fake();
    $payment = nmiPayment();
    $pauseWasSet = false;
    $saleCalls = 0;
    fakeNmi(function () use ($payment, &$pauseWasSet, &$saleCalls) {
        $saleCalls++;
        $pauseWasSet = Cache::has("nmi:pause:{$payment->id}");

        throw new ConnectionException('timeout');
    });

    chargeNmi($payment)->assertOk()->assertJson(['outcome' => 'unclear']);

    expect($pauseWasSet)->toBeTrue()
        ->and(Cache::has("nmi:pause:{$payment->id}"))->toBeTrue()
        ->and($payment->fresh()->status)->toBe('pending');
    chargeNmi($payment)->assertStatus(409);
    expect($saleCalls)->toBe(1);
});

it('returns a gateway error as a fixed message with 502 and clears the pause', function () {
    $payment = nmiPayment();
    fakeNmi([['type' => 'generalError', 'error_code' => 'E_GENERIC_ERROR', 'message' => 'Invalid payment token'], 400]);

    chargeNmi($payment)->assertStatus(502)->assertJson([
        'outcome' => 'error',
        'message' => 'Your payment could not be processed. Please try again or use a different card.',
    ]);

    expect(Cache::has("nmi:pause:{$payment->id}"))->toBeFalse();
});

it('never completes on an amount mismatch and logs an error', function () {
    Log::spy();
    $payment = nmiPayment();
    Http::fake([
        'sandbox.nmi.com/api/v5/payments/sale' => Http::response(nmiApproved()[0]),
        'sandbox.nmi.com/api/query.php' => function () use ($payment) {
            return Http::response(nmiXml(nmiXmlTransaction('9001', Payment::find($payment->id)->nmi_order_id, amount: '1.00')));
        },
    ]);

    chargeNmi($payment)->assertOk()->assertJson(['outcome' => 'unclear']);

    expect($payment->fresh()->status)->toBe('pending');
    Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'does not match'))->atLeast()->once();
});

it('keeps only the approved sale when a retried order id returns several rows', function () {
    $payment = nmiPayment(['nmi_order_id' => '11111111-1111-4111-8111-111111111111', 'nmi_first_attempt_at' => now()]);
    $reply = NmiQueryReply::fromXml(nmiXml(
        nmiXmlTransaction('1', $payment->nmi_order_id, 'failed', success: false),
        nmiXmlTransaction('2', $payment->nmi_order_id),
    ));

    expect(app(NmiChargeResolver::class)->apply($payment, $reply))->toBe('paid')
        ->and($payment->fresh()->nmi_transaction_id)->toBe('2');
});

it('does not apply when two different approved sales exist for one order', function () {
    Log::spy();
    $payment = nmiPayment(['nmi_order_id' => '22222222-2222-4222-8222-222222222222', 'nmi_first_attempt_at' => now()]);
    $reply = NmiQueryReply::fromXml(nmiXml(
        nmiXmlTransaction('1', $payment->nmi_order_id),
        nmiXmlTransaction('2', $payment->nmi_order_id),
    ));

    expect(app(NmiChargeResolver::class)->apply($payment, $reply))->toBe('duplicate')
        ->and($payment->fresh()->status)->toBe('pending');
});

it('does not complete when the currency differs', function () {
    $payment = nmiPayment(['nmi_order_id' => '33333333-3333-4333-8333-333333333333']);
    $reply = NmiQueryReply::fromXml(nmiXml(nmiXmlTransaction('1', $payment->nmi_order_id, currency: 'GBP')));

    expect(app(NmiChargeResolver::class)->apply($payment, $reply))->toBe('mismatch')
        ->and($payment->fresh()->status)->toBe('pending');
});

it('does not complete a cancelled payment and logs it', function () {
    Log::spy();
    $payment = nmiPayment(['nmi_order_id' => '44444444-4444-4444-8444-444444444444', 'status' => 'cancelled']);
    $reply = NmiQueryReply::fromXml(nmiXml(nmiXmlTransaction('1', $payment->nmi_order_id)));

    expect(app(NmiChargeResolver::class)->apply($payment, $reply))->toBe('duplicate')
        ->and($payment->fresh()->status)->toBe('cancelled');
    Log::shouldHaveReceived('error')->once();
});

it('treats a pending or empty lookup as pending, never as failed', function () {
    $payment = nmiPayment(['nmi_order_id' => '55555555-5555-4555-8555-555555555555']);

    expect(app(NmiChargeResolver::class)->apply($payment, NmiQueryReply::fromXml(nmiXml())))->toBe('not_found')
        ->and(app(NmiChargeResolver::class)->apply($payment, NmiQueryReply::fromXml(nmiXml(nmiXmlTransaction('1', $payment->nmi_order_id, 'in_progress')))))->toBe('pending')
        ->and($payment->fresh()->status)->toBe('pending');
});

it('reads an error response from query.php as a failed lookup', function () {
    $reply = NmiQueryReply::fromXml('<nm_response><error_response>Specified API key not found REFID:1</error_response></nm_response>');

    expect($reply->ok)->toBeFalse()->and($reply->error)->toBe('Specified API key not found');
});

it('writes only a 3ds_failed row for a 3DS failure report and makes no gateway call', function () {
    Http::fake();
    $payment = nmiPayment(account: ['three_ds_enabled' => true]);

    chargeNmi($payment, ['three_ds_failed' => true, 'payment_token' => null])->assertOk()->assertJson(['outcome' => '3ds_failed']);

    Http::assertNothingSent();
    expect($payment->nmiChargeAttempts()->first()->status)->toBe('3ds_failed')
        ->and($payment->fresh()->nmi_first_attempt_at)->toBeNull();
});

it('requires the 3DS result for a card when 3DS is on', function () {
    Http::fake();
    $payment = nmiPayment(account: ['three_ds_enabled' => true]);

    chargeNmi($payment)->assertStatus(422);
    Http::assertNothingSent();
});

it('sends the 3DS result as cardholder_auth', function () {
    $payment = nmiPayment(account: ['three_ds_enabled' => true]);
    fakeNmi([['id' => '9005', 'response_code' => '200']]);

    chargeNmi($payment, ['cavv' => 'AAA=', 'eci' => '05', 'three_ds_version' => '2.2.0'])->assertOk();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sale')
        && $r->data()['cardholder_auth']['cavv'] === 'AAA='
        && $r->data()['cardholder_auth']['eci'] === '05');
});

it('refuses a charge without consent, for an inactive account, or a paid payment', function () {
    Http::fake();
    $payment = nmiPayment();
    $payment->consents()->delete();
    chargeNmi($payment)->assertStatus(422);

    $payment = nmiPayment(account: ['is_active' => false]);
    chargeNmi($payment)->assertStatus(422);

    $payment = nmiPayment(['status' => 'completed']);
    chargeNmi($payment)->assertStatus(422);

    Http::assertNothingSent();
});

it('refuses a second charge once an attempt row says approved', function () {
    Http::fake();
    $payment = nmiPayment();
    $payment->nmiChargeAttempts()->create(['sequence' => 1, 'method' => 'card', 'status' => 'approved']);

    chargeNmi($payment)->assertStatus(422);
    Http::assertNothingSent();
});

it('rate limits real sales at 5 per payment per hour but not 3DS reports', function () {
    config(['services.card_lock.max_attempts' => 99]); // keep the card lock out of this limiter test
    $payment = nmiPayment(account: ['three_ds_enabled' => false]);
    fakeNmi([['id' => '9006', 'response_code' => '201']]);

    foreach (range(1, 5) as $i) {
        chargeNmi($payment)->assertOk();
        Cache::forget("nmi:pause:{$payment->id}");
    }
    chargeNmi($payment)->assertStatus(429);

    // A 3DS failure report still goes through its own limiter.
    chargeNmi($payment, ['three_ds_failed' => true, 'payment_token' => null])->assertOk();
});

it('locks the card after repeated declines using the shared guard', function () {
    $payment = nmiPayment();
    fakeNmi([['id' => '9007', 'response_code' => '200']]);

    foreach (range(1, 3) as $i) {
        $r = chargeNmi($payment);
        Cache::forget("nmi:pause:{$payment->id}");
    }

    $r->assertStatus(423)->assertJson(['outcome' => 'locked']);
    expect($payment->fresh()->isCardLocked())->toBeTrue();
});

it('does not count 3ds_failed or unclear toward the card lock', function () {
    $payment = nmiPayment();
    foreach (range(1, 3) as $i) {
        $payment->nmiChargeAttempts()->create(['sequence' => $i, 'method' => 'card', 'status' => $i === 1 ? '3ds_failed' : 'unclear']);
    }
    app(CardLockGuard::class)->recordFailure($payment);

    expect($payment->fresh()->isCardLocked())->toBeFalse();
});

it('runs a guarded lookup from the status endpoint at most once per few seconds', function () {
    $payment = nmiPayment(['nmi_order_id' => '66666666-6666-4666-8666-666666666666', 'nmi_first_attempt_at' => now()]);
    fakeNmi([[]], nmiXml(nmiXmlTransaction('7', $payment->nmi_order_id)));

    $this->getJson("/pay/{$payment->uuid}/nmi/status")->assertOk()->assertJson(['status' => 'completed']);
    $this->getJson("/pay/{$payment->uuid}/nmi/status")->assertOk();

    Http::assertSentCount(1);
});

it('does not look up a payment that was never charged', function () {
    Http::fake();
    $payment = nmiPayment();

    $this->getJson("/pay/{$payment->uuid}/nmi/status")->assertOk()->assertJson(['status' => 'pending']);
    Http::assertNothingSent();
});

it('converts amounts with integer math and rejects odd decimals', function () {
    expect(NmiAmount::centsToDecimal(5))->toBe('0.05')
        ->and(NmiAmount::centsToDecimal(1234))->toBe('12.34')
        ->and(NmiAmount::decimalToCents('12.3'))->toBe(1230)
        ->and(NmiAmount::decimalToCents('12.340'))->toBe(1234)
        ->and(NmiAmount::decimalToCents('12.345'))->toBeNull()
        ->and(NmiAmount::decimalToCents('-1'))->toBeNull();
});

it('classifies sale replies', function () {
    expect(NmiSaleReply::fromResult(['status' => 200, 'body' => ['id' => '1', 'response_code' => '100']])->outcome)->toBe('approved')
        ->and(NmiSaleReply::fromResult(['status' => 200, 'body' => ['response_code' => '100']])->outcome)->toBe('unclear')
        ->and(NmiSaleReply::fromResult(['status' => 503, 'body' => []])->outcome)->toBe('unclear')
        ->and(NmiSaleReply::fromResult(['status' => 200, 'body' => ['id' => '1', 'response_code' => '430']])->outcome)->toBe('unclear')
        ->and(NmiSaleReply::fromResult(['status' => 200, 'body' => ['id' => '1', 'response_code' => '300']])->outcome)->toBe('error')
        ->and(NmiSaleReply::fromResult(['status' => 400, 'body' => ['error_code' => 'E_INVALID_SUBMISSION']])->outcome)->toBe('error');
});

it('settles an unclear charge from the queued lookup', function () {
    $payment = nmiPayment(['nmi_order_id' => '77777777-7777-4777-8777-777777777777', 'nmi_first_attempt_at' => now()]);
    $payment->nmiChargeAttempts()->create(['sequence' => 1, 'method' => 'card', 'status' => 'unclear']);
    fakeNmi([[]], nmiXml(nmiXmlTransaction('8', $payment->nmi_order_id)));

    (new ResolveNmiCharge($payment->id))->handle(app(NmiChargeResolver::class));

    $payment->refresh();
    expect($payment->status)->toBe('completed')
        ->and($payment->nmiChargeAttempts()->first()->status)->toBe('approved')
        ->and($payment->nmiChargeAttempts()->first()->transaction_id)->toBe('8');
});

it('sends the billing email only for a production account', function () {
    $sandbox = nmiPayment();
    fakeNmi([['id' => '9010', 'response_code' => '200']]);
    chargeNmi($sandbox)->assertOk();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sale') && ! isset($r->data()['billing_address']['email']));

    $production = nmiPayment(account: ['environment' => 'production']);
    Http::fake(['secure.nmi.com/api/v5/payments/sale' => Http::response(['id' => '9011', 'response_code' => '200'])]);
    chargeNmi($production)->assertOk();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'secure.nmi.com') && ($r->data()['billing_address']['email'] ?? null) === 'client@example.com');
});
