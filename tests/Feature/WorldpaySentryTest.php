<?php

use App\Models\Payment;
use App\Models\WorldpayPage;
use App\Services\Worldpay\WorldpayChargeResolver;
use App\Services\Worldpay\WorldpaySentry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

class FakeWorldpaySentry extends WorldpaySentry
{
    /** @var list<array{message: string, tags: array, context: array, fingerprint: array, level: string}> */
    public array $calls = [];

    public function capture(string $message, array $tags, array $context, array $fingerprint, string $level = 'warning'): void
    {
        $this->calls[] = compact('message', 'tags', 'context', 'fingerprint', 'level');
    }
}

function wsFake(): FakeWorldpaySentry
{
    $fake = new FakeWorldpaySentry;
    app()->instance(WorldpaySentry::class, $fake);

    return $fake;
}

/** @return array{0: Payment, 1: WorldpayPage} */
function wsPayment(): array
{
    $payment = Payment::factory()->worldpay()->create(['amount' => 1234, 'status' => 'pending']);
    $page = WorldpayPage::create([
        'payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1',
        'url' => 'https://hpp.worldpay.com/a', 'expires_at' => now()->addDay(),
    ]);

    return [$payment, $page];
}

beforeEach(function () {
    Cache::flush();
    Http::swap(new Factory);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => []]], 200)]);
});

it('sends a failed return to Sentry once with tags, context and a reason fingerprint', function () {
    $fake = wsFake();
    [$payment, $page] = wsPayment();

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=D261120-T1&messages=Card%20Refused,Other");

    expect($fake->calls)->toHaveCount(1);
    $call = $fake->calls[0];

    expect($call['tags']['payment.provider'])->toBe('worldpay')
        ->and($call['tags']['worldpay.return'])->toBe('failure')
        ->and($call['tags']['worldpay.account_id'])->toBe($payment->worldpay_account_id)
        ->and($call['context']['transaction_reference'])->toBe($page->transaction_reference)
        ->and($call['context']['error_ref'])->toBe('D261120-T1')
        ->and($call['context']['error_text'])->toBe('Card Refused,Other')
        ->and($call['context']['worldpay_has_payment_row'])->toBeFalse()
        ->and($call['fingerprint'])->toBe(['worldpay', 'return', 'failure', 'cardrefused'])
        ->and($call['level'])->toBe('warning');

    // No card value, hosted page URL, credentials or client email, and the unique reference is not in the fingerprint.
    $dump = json_encode($call);
    expect($dump)->not->toContain('hpp.worldpay.com')
        ->and(in_array('D261120-T1', $call['fingerprint'], true))->toBeFalse();

    if ($payment->client_email) {
        expect($dump)->not->toContain($payment->client_email);
    }
});

it('groups a return with no reason under no-reason', function () {
    $fake = wsFake();
    [$payment] = wsPayment();

    $this->get("/pay/{$payment->uuid}?worldpay_return=error&errorRefNumber=E1");

    expect($fake->calls[0]['fingerprint'])->toBe(['worldpay', 'return', 'error', 'no-reason']);
});

it('sends at most one return per payment every ten minutes and ignores cancel and expiry', function () {
    $fake = wsFake();
    [$payment] = wsPayment();

    $this->get("/pay/{$payment->uuid}?worldpay_return=cancel&errorRefNumber=C1");
    $this->get("/pay/{$payment->uuid}?worldpay_return=expiry&errorRefNumber=C2");
    expect($fake->calls)->toHaveCount(0);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=F1");
    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=F2");
    expect($fake->calls)->toHaveCount(1);

    $this->travel(11)->minutes();
    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=F3");
    expect($fake->calls)->toHaveCount(2);
});

it('sends a new refused lookup to Sentry once, grouped by the Worldpay event', function () {
    $fake = wsFake();
    [$payment, $page] = wsPayment();
    Http::swap(new Factory);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => [[
        'timestamp' => '2026-11-20T06:37:12Z', 'paymentId' => 'payR', 'transactionReference' => $page->transaction_reference,
        'lastEvent' => 'authorizationRefused', 'value' => ['amount' => 1234, 'currency' => 'GBP'],
    ]]]], 200)]);

    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');
    app(WorldpayChargeResolver::class)->resolve($payment->fresh(), 'poll');

    expect($fake->calls)->toHaveCount(1);
    $call = $fake->calls[0];
    expect($call['tags']['worldpay.last_event'])->toBe('authorizationRefused')
        ->and($call['context']['worldpay_event'])->toBe('authorizationRefused')
        ->and($call['context']['transaction_reference'])->toBe($page->transaction_reference)
        ->and($call['fingerprint'])->toBe(['worldpay', 'lookup', 'authorizationRefused']);
});

it('does not send a pending lookup to Sentry', function () {
    $fake = wsFake();
    [$payment, $page] = wsPayment();
    Http::swap(new Factory);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => [[
        'timestamp' => '2026-11-20T06:37:12Z', 'paymentId' => 'payP', 'transactionReference' => $page->transaction_reference,
        'lastEvent' => 'authorizationSucceeded', 'value' => ['amount' => 1234, 'currency' => 'GBP'],
    ]]]], 200)]);

    app(WorldpayChargeResolver::class)->resolve($payment, 'poll');

    expect($fake->calls)->toHaveCount(0);
});

it('never sends events to a real Sentry project from the test suite', function () {
    expect(config('sentry.dsn'))->toBeEmpty();
});

it('does nothing and does not throw when Sentry is not set up', function () {
    (new WorldpaySentry)->capture('test', ['a' => 'b'], ['c' => 'd'], ['x']);

    expect(true)->toBeTrue();
});
