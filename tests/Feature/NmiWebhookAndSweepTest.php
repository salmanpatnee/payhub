<?php

use App\Jobs\HandleNmiWebhookJob;
use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Models\ProcessedNmiEvent;
use App\Services\Nmi\NmiChargeResolver;
use App\Services\Nmi\NmiStalePaymentSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake([SendPaymentNotification::class]);
});

function nmiHookPayment(array $attributes = [], ?string $signingKey = 'signing-key'): Payment
{
    $payment = Payment::factory()->nmi()->create(array_merge([
        'amount' => 1234, 'currency' => 'usd', 'status' => 'pending',
        'nmi_order_id' => (string) Str::uuid(),
        'nmi_first_attempt_at' => now()->subHour(),
        'nmi_last_attempt_at' => now()->subHour(),
    ], $attributes));

    $payment->nmiAccount->forceFill(['webhook_signing_key' => $signingKey])->save();

    return $payment->fresh();
}

function nmiSaleXml(Payment $payment, string $id = '501'): string
{
    return '<nm_response><transaction><transaction_id>'.$id.'</transaction_id><condition>pendingsettlement</condition>'
        .'<order_id>'.$payment->nmi_order_id.'</order_id><currency>USD</currency>'
        .'<action><amount>12.34</amount><action_type>sale</action_type><success>1</success></action></transaction></nm_response>';
}

function postNmiHook(Payment $payment, array $body, ?string $key = 'signing-key', string $nonce = 'abc123'): TestResponse
{
    $raw = json_encode($body);
    $sig = hash_hmac('sha256', $nonce.'.'.$raw, (string) $key);

    return test()->call('POST', "/webhook/nmi/{$payment->nmi_account_id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_SIGNATURE' => "t={$nonce},s={$sig}",
    ], $raw);
}

function saleEvent(Payment $payment, string $eventId = 'evt-1', string $type = 'transaction.sale.success'): array
{
    return ['event_id' => $eventId, 'event_type' => $type, 'event_body' => ['order_id' => $payment->nmi_order_id]];
}

it('rejects a bad or missing signature and an account without a signing key', function () {
    Queue::fake();
    $payment = nmiHookPayment();

    postNmiHook($payment, saleEvent($payment), 'wrong-key')->assertStatus(401);
    test()->postJson("/webhook/nmi/{$payment->nmi_account_id}", saleEvent($payment))->assertStatus(401);

    $none = nmiHookPayment(signingKey: null);
    postNmiHook($none, saleEvent($none))->assertStatus(401);

    Queue::assertNothingPushed();
});

it('answers 200 and queues the job for a good sale event', function () {
    Queue::fake();
    $payment = nmiHookPayment();

    postNmiHook($payment, saleEvent($payment))->assertOk();

    Queue::assertPushed(HandleNmiWebhookJob::class, fn ($job) => $job->orderId === $payment->nmi_order_id && $job->eventId === 'evt-1');
});

it('ignores other event types with 200', function () {
    Queue::fake();
    $payment = nmiHookPayment();

    postNmiHook($payment, saleEvent($payment, type: 'transaction.refund.success'))->assertOk();

    Queue::assertNothingPushed();
});

it('completes the payment from a lookup, never from the webhook body', function () {
    $payment = nmiHookPayment();
    Http::fake(['*' => Http::response(nmiSaleXml($payment))]);

    // The body claims a different amount and status: it must not matter.
    $body = saleEvent($payment) + ['event_body_status' => 'forged'];
    postNmiHook($payment, $body)->assertOk();

    expect($payment->fresh()->status)->toBe('completed');
});

it('skips a replayed event id and writes the dedupe row only after success', function () {
    $payment = nmiHookPayment();
    Http::fake(['*' => Http::response(nmiSaleXml($payment))]);
    $job = new HandleNmiWebhookJob($payment->nmi_account_id, 'evt-9', $payment->nmi_order_id);

    $job->handle(app(NmiChargeResolver::class));
    expect(ProcessedNmiEvent::where('event_id', 'evt-9')->exists())->toBeTrue();

    Http::fake(); // a replay makes no lookup
    $job->handle(app(NmiChargeResolver::class));
    Http::assertNothingSent();
});

it('does not write the dedupe row when the lookup fails, so the job can retry', function () {
    $payment = nmiHookPayment();
    Http::fake(['*' => Http::response('<nm_response><error_response>Specified API key not found</error_response></nm_response>')]);
    $job = new HandleNmiWebhookJob($payment->nmi_account_id, 'evt-10', $payment->nmi_order_id);

    expect(fn () => $job->handle(app(NmiChargeResolver::class)))->toThrow(RuntimeException::class);
    expect(ProcessedNmiEvent::where('event_id', 'evt-10')->exists())->toBeFalse();
});

it('ignores an unknown order id and an order id from another account', function () {
    Log::spy();
    $payment = nmiHookPayment();
    $other = nmiHookPayment();
    Http::fake();

    (new HandleNmiWebhookJob($payment->nmi_account_id, 'evt-11', 'does-not-exist'))->handle(app(NmiChargeResolver::class));
    (new HandleNmiWebhookJob($payment->nmi_account_id, 'evt-12', $other->nmi_order_id))->handle(app(NmiChargeResolver::class));

    Http::assertNothingSent();
    expect($other->fresh()->status)->toBe('pending');
});

it('sweeps pending payments charged in the last 48 hours and ignores inactive accounts', function () {
    $recent = nmiHookPayment();
    $recent->nmiAccount->forceFill(['is_active' => false])->save();
    $old = nmiHookPayment(['nmi_last_attempt_at' => now()->subHours(49)]);
    Http::fake(['*' => Http::response(nmiSaleXml($recent))]);

    (new NmiStalePaymentSweep)();

    expect($recent->fresh()->status)->toBe('completed')
        ->and($old->fresh()->status)->toBe('pending');
});

it('gives up 30 minutes after the last attempt when NMI has no transaction, once', function () {
    Log::spy();
    $payment = nmiHookPayment(['nmi_last_attempt_at' => now()->subMinutes(45)]);
    $payment->nmiChargeAttempts()->create(['sequence' => 1, 'method' => 'card', 'status' => 'unclear']);
    Cache::put("nmi:pause:{$payment->id}", 1, 120);
    Http::fake(['*' => Http::response('<nm_response></nm_response>')]);

    (new NmiStalePaymentSweep)();

    $attempt = $payment->nmiChargeAttempts()->first();
    expect($attempt->status)->toBe('error')
        ->and($attempt->reason)->toBe('No transaction found at NMI')
        ->and(Cache::has("nmi:pause:{$payment->id}"))->toBeFalse();

    Http::fake();
    (new NmiStalePaymentSweep)();
    Http::assertNothingSent(); // stopped looking
});

it('keeps looking while the last attempt is under 30 minutes old', function () {
    $payment = nmiHookPayment(['nmi_last_attempt_at' => now()->subMinutes(5)]);
    $payment->nmiChargeAttempts()->create(['sequence' => 1, 'method' => 'card', 'status' => 'unclear']);
    Http::fake(['*' => Http::response('<nm_response></nm_response>')]);

    (new NmiStalePaymentSweep)();
    (new NmiStalePaymentSweep)();

    expect($payment->nmiChargeAttempts()->first()->status)->toBe('unclear');
});
