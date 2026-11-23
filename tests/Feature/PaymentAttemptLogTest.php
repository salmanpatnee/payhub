<?php

use App\Enums\PaymentProvider;
use App\Jobs\HandleVivaWebhookJob;
use App\Jobs\HandleWhopWebhookJob;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\RevolutAccount;
use App\Models\SquareAccount;
use App\Models\StripeAccount;
use App\Models\User;
use App\Models\VivaAccount;
use App\Models\WhopAccount;
use App\Services\PaymentAttempts\AttemptData;
use App\Services\PaymentAttempts\AttemptExtractor;
use App\Services\PaymentAttempts\AttemptPayloadSanitizer;
use App\Services\PaymentAttempts\PaymentAttemptLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    foreach (['admin', 'agent', 'account'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

// ---- Helpers (prefixed `pal` so they never clash with other test files) ----

function palStripe(string $type, string $eventId, array $intent = []): array
{
    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_pal']);
    $payment = Payment::factory()->create([
        'stripe_account_id' => $account->id,
        'stripe_payment_intent_id' => 'pi_pal',
        'amount' => 5000, 'currency' => 'usd', 'status' => 'pending',
    ]);

    return [$account, $payment];
}

function palStripePost(StripeAccount $account, string $type, string $eventId, array $intent = []): TestResponse
{
    $body = json_encode([
        'id' => $eventId, 'type' => $type, 'created' => 1790000000,
        'data' => ['object' => array_merge(['id' => 'pi_pal', 'amount' => 5000, 'currency' => 'usd'], $intent)],
    ]);

    return test()->call('POST', "/webhook/stripe/{$account->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => fakeStripeSignature($body, 'whsec_pal'),
    ], $body);
}

function palStripeFailure(array $extra = []): array
{
    return ['status' => 'requires_payment_method', 'last_payment_error' => array_merge([
        'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
        'message' => 'Your card has insufficient funds.',
        'payment_method' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'funding' => 'credit', 'country' => 'US', 'number' => '4242424242424242']],
    ], $extra)];
}

function palRevolutPost(RevolutAccount $account, string $event, string $orderId): TestResponse
{
    $body = json_encode(['event' => $event, 'order_id' => $orderId]);
    $ts = (string) (int) round(microtime(true) * 1000);

    return test()->call('POST', "/webhook/revolut/{$account->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_REVOLUT_REQUEST_TIMESTAMP' => $ts,
        'HTTP_REVOLUT_SIGNATURE' => 'v1='.hash_hmac('sha256', 'v1.'.$ts.'.'.$body, 'rev_secret'),
    ], $body);
}

function palSquarePost(SquareAccount $account, string $eventId, array $squarePayment): TestResponse
{
    $body = json_encode([
        'event_id' => $eventId, 'type' => 'payment.updated', 'created_at' => '2026-11-20T10:00:00Z',
        'data' => ['type' => 'payment', 'object' => ['payment' => $squarePayment]],
    ]);
    $sig = base64_encode(hash_hmac('sha256', route('webhook.square', $account, true).$body, 'sq_key', true));

    return test()->call('POST', "/webhook/square/{$account->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $sig,
    ], $body);
}

function palWhopJob(WhopAccount $account, string $webhookId, string $type, Payment $payment, array $data = []): void
{
    $body = json_encode(['type' => $type, 'data' => array_merge([
        'id' => 'pay_whop_1', 'subtotal' => ['amount' => '12.34', 'currency' => 'gbp'],
        'metadata' => ['payment_id' => $payment->uuid],
    ], $data)]);

    (new HandleWhopWebhookJob($account->id, $webhookId, $body))->handle();
}

function palVivaFake(string ...$statusIds): void
{
    $sequence = Http::sequence();
    foreach ($statusIds as $statusId) {
        $sequence->push([
            'statusId' => $statusId, 'orderCode' => 'order_pal', 'amount' => 12.5, 'currencyCode' => 826,
            'transactionTypeId' => 5, 'cardNumber' => '4111111111111111',
        ]);
    }

    Http::fake([
        '*demo-accounts.vivapayments.com/connect/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
        '*demo-api.vivapayments.com/checkout/v2/transactions/*' => $sequence,
    ]);
}

function palVivaPost(VivaAccount $account, string $messageId, int $eventTypeId = 1796, string $txn = 'txn_pal'): TestResponse
{
    $body = json_encode(['MessageId' => $messageId, 'EventTypeId' => $eventTypeId, 'EventData' => ['OrderCode' => 'order_pal', 'TransactionId' => $txn]]);

    return test()->call('POST', "/webhook/viva/{$account->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
}

// ---- AC-1: schema ----

it('has the shared table, unique indexes and a plain string provider column', function () {
    expect(Schema::hasTable('payment_attempts'))->toBeTrue();

    $payment = Payment::factory()->create();
    PaymentAttempt::factory()->create(['payment_id' => $payment->id, 'provider' => 'someday', 'event_key' => 'k1']);

    expect(fn () => PaymentAttempt::factory()->create(['payment_id' => $payment->id, 'sequence' => 1, 'event_key' => 'k2']))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => PaymentAttempt::factory()->create(['payment_id' => $payment->id, 'provider' => 'someday', 'sequence' => 2, 'event_key' => 'k1']))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($payment->paymentAttempts()->count())->toBe(1);
});

// ---- AC-4: Stripe ----

it('logs a Stripe failure with decline code, reason, card brand and last4', function () {
    Queue::fake();
    [$account, $payment] = palStripe('x', 'x');

    palStripePost($account, 'payment_intent.payment_failed', 'evt_f1', palStripeFailure())->assertOk();

    $row = $payment->paymentAttempts()->sole();
    expect($row->status)->toBe('failed')
        ->and($row->sequence)->toBe(1)
        ->and($row->reference)->toBe('pi_pal')
        ->and($row->error_code)->toBe('insufficient_funds')
        ->and($row->reason)->toBe('Your card has insufficient funds.')
        ->and($row->amount)->toBe(5000)->and($row->amount_reported)->toBeTrue()
        ->and($row->event_key)->toBe('evt_f1')
        ->and($row->response['last_payment_error']['card'])->toBe(['brand' => 'visa', 'last4' => '4242', 'funding' => 'credit', 'country' => 'US']);
});

it('logs two Stripe failures then a success as sequence 1, 2, 3 and leaves status to the job', function () {
    Queue::fake();
    [$account, $payment] = palStripe('x', 'x');

    palStripePost($account, 'payment_intent.payment_failed', 'evt_a', palStripeFailure());
    palStripePost($account, 'payment_intent.payment_failed', 'evt_b', palStripeFailure(['code' => 'expired_card', 'decline_code' => null]));
    palStripePost($account, 'payment_intent.succeeded', 'evt_c');

    $rows = $payment->paymentAttempts()->orderBy('sequence')->get();
    expect($rows->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($rows->pluck('status')->all())->toBe(['failed', 'failed', 'succeeded'])
        ->and($rows[1]->error_code)->toBe('expired_card');
});

it('logs a duplicate Stripe event once', function () {
    Queue::fake();
    [$account, $payment] = palStripe('x', 'x');

    palStripePost($account, 'payment_intent.payment_failed', 'evt_dup', palStripeFailure());
    palStripePost($account, 'payment_intent.payment_failed', 'evt_dup', palStripeFailure());

    expect($payment->paymentAttempts()->count())->toBe(1);
});

it('logs a late Stripe failure after completion and leaves the payment completed', function () {
    Queue::fake();
    [$account, $payment] = palStripe('x', 'x');
    $payment->update(['status' => 'completed']);

    palStripePost($account, 'payment_intent.payment_failed', 'evt_late', palStripeFailure())->assertOk();

    expect($payment->paymentAttempts()->count())->toBe(1)
        ->and($payment->fresh()->status)->toBe('completed');
    Queue::assertNothingPushed();
});

it('writes nothing for an unknown Stripe payment or another account and still returns 200', function () {
    Queue::fake();
    [$account, $payment] = palStripe('x', 'x');
    $other = StripeAccount::factory()->create(['webhook_secret' => 'whsec_pal']);

    palStripePost($account, 'payment_intent.payment_failed', 'evt_u1', ['id' => 'pi_unknown'])->assertOk();
    palStripePost($other, 'payment_intent.payment_failed', 'evt_u2')->assertOk();

    expect(PaymentAttempt::count())->toBe(0);
});

it('keeps a Stripe success with no last_payment_error and no amount without crashing', function () {
    Queue::fake();
    [$account, $payment] = palStripe('x', 'x');

    palStripePost($account, 'payment_intent.succeeded', 'evt_s', ['amount' => null])->assertOk();

    $row = $payment->paymentAttempts()->sole();
    expect($row->error_code)->toBeNull()->and($row->reason)->toBeNull()
        ->and($row->amount)->toBe(5000)->and($row->amount_reported)->toBeFalse();
});

// ---- AC-5: Revolut ----

it('logs Revolut success and failure with the order id and the payment amount', function () {
    Queue::fake();
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'rev_secret']);
    $payment = Payment::factory()->revolut()->create(['revolut_account_id' => $account->id, 'revolut_order_id' => 'ord_1', 'amount' => 2500, 'currency' => 'gbp']);

    palRevolutPost($account, 'ORDER_PAYMENT_FAILED', 'ord_1')->assertOk();
    palRevolutPost($account, 'ORDER_PAYMENT_FAILED', 'ord_1')->assertOk();
    palRevolutPost($account, 'ORDER_COMPLETED', 'ord_1')->assertOk();

    $rows = $payment->paymentAttempts()->orderBy('sequence')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status)->toBe('failed')->and($rows[0]->event_key)->toBe('ord_1:ORDER_PAYMENT_FAILED')
        ->and($rows[0]->amount)->toBe(2500)->and($rows[0]->amount_reported)->toBeFalse()
        ->and($rows[0]->error_code)->toBeNull()->and($rows[0]->reason)->toBeNull()
        ->and($rows[1]->status)->toBe('succeeded');
});

it('logs a late Revolut failure for a completed payment', function () {
    Queue::fake();
    $account = RevolutAccount::factory()->create(['webhook_secret' => 'rev_secret']);
    $payment = Payment::factory()->revolut()->create(['revolut_account_id' => $account->id, 'revolut_order_id' => 'ord_2', 'status' => 'completed']);

    palRevolutPost($account, 'ORDER_PAYMENT_FAILED', 'ord_2')->assertOk();

    expect($payment->paymentAttempts()->count())->toBe(1)->and($payment->fresh()->status)->toBe('completed');
});

// ---- AC-6: Square ----

it('logs Square failed, completed and canceled and skips APPROVED and repeated statuses', function () {
    Queue::fake();
    $account = SquareAccount::factory()->create(['webhook_signature_key' => 'sq_key']);
    $payment = Payment::factory()->square()->create(['square_account_id' => $account->id, 'square_payment_id' => 'sq_1', 'amount' => 3000, 'currency' => 'usd']);
    $money = ['amount' => 3000, 'currency' => 'USD'];

    palSquarePost($account, 'e1', ['id' => 'sq_1', 'status' => 'APPROVED', 'amount_money' => $money])->assertOk();
    palSquarePost($account, 'e2', ['id' => 'sq_1', 'status' => 'FAILED', 'amount_money' => $money, 'card_details' => ['errors' => [['code' => 'CARD_DECLINED', 'category' => 'PAYMENT_METHOD_ERROR', 'detail' => 'Card declined.']]]])->assertOk();
    palSquarePost($account, 'e3', ['id' => 'sq_1', 'status' => 'COMPLETED', 'amount_money' => $money])->assertOk();
    palSquarePost($account, 'e4', ['id' => 'sq_1', 'status' => 'COMPLETED', 'amount_money' => $money])->assertOk(); // refund update repeats status
    palSquarePost($account, 'e5', ['id' => 'sq_1', 'status' => 'CANCELED', 'amount_money' => $money])->assertOk();

    $rows = $payment->paymentAttempts()->orderBy('sequence')->get();
    expect($rows->pluck('status')->all())->toBe(['failed', 'succeeded', 'cancelled'])
        ->and($rows[0]->error_code)->toBe('CARD_DECLINED')->and($rows[0]->reason)->toBe('Card declined.')
        ->and($rows[0]->amount)->toBe(3000)->and($rows[0]->amount_reported)->toBeTrue();
});

it('finds a Square payment by reference_id when its payment id was never saved', function () {
    Queue::fake();
    $account = SquareAccount::factory()->create(['webhook_signature_key' => 'sq_key']);
    $payment = Payment::factory()->square()->create(['square_account_id' => $account->id, 'square_payment_id' => null]);
    $other = SquareAccount::factory()->create(['webhook_signature_key' => 'sq_key']);

    palSquarePost($account, 'e1', ['id' => 'sq_new', 'status' => 'FAILED', 'reference_id' => $payment->formattedReferenceCode()])->assertOk();
    palSquarePost($other, 'e2', ['id' => 'sq_new2', 'status' => 'FAILED', 'reference_id' => $payment->formattedReferenceCode()])->assertOk();

    expect($payment->paymentAttempts()->count())->toBe(1)->and(PaymentAttempt::count())->toBe(1);
});

// ---- AC-7: Viva ----

it('logs Viva status F as succeeded, only after the API re-fetch, and E as failed without a status change', function () {
    Queue::fake();
    $account = VivaAccount::factory()->create();
    $payment = Payment::factory()->viva()->create(['viva_account_id' => $account->id, 'viva_order_code' => 'order_pal', 'status' => 'pending', 'amount' => 1250, 'currency' => 'gbp']);

    palVivaFake('E', 'F');
    (new HandleVivaWebhookJob($account->id, 'order_pal', 'txn_1', 'msg_1', 1796))->handle();
    (new HandleVivaWebhookJob($account->id, 'order_pal', 'txn_2', 'msg_2', 1796))->handle();

    $rows = $payment->paymentAttempts()->orderBy('sequence')->get();
    expect($rows->pluck('status')->all())->toBe(['failed', 'succeeded'])
        ->and($rows[1]->amount)->toBe(1250)->and($rows[1]->amount_reported)->toBeTrue()->and($rows[1]->currency)->toBe('gbp')
        ->and($rows[1]->reference)->toBe('txn_2')
        ->and(json_encode($rows->toArray()))->not->toContain('4111111111111111')
        ->and($payment->fresh()->status)->toBe('completed');
});

it('logs a confirmed Viva 1798 event as failed and never changes the payment status', function () {
    Queue::fake();
    $account = VivaAccount::factory()->create();
    $payment = Payment::factory()->viva()->create(['viva_account_id' => $account->id, 'viva_order_code' => 'order_pal', 'status' => 'pending']);

    palVivaFake('F');
    (new HandleVivaWebhookJob($account->id, 'order_pal', 'txn_1', 'msg_f', 1798))->handle();

    expect($payment->paymentAttempts()->sole()->event_key)->toBe('msg_f')
        ->and($payment->fresh()->status)->toBe('pending');
});

it('writes no Viva row when the API does not confirm the transaction, or the transaction has no id', function () {
    Queue::fake();
    $account = VivaAccount::factory()->create();
    $payment = Payment::factory()->viva()->create(['viva_account_id' => $account->id, 'viva_order_code' => 'order_pal']);

    Http::fake([
        '*demo-accounts.vivapayments.com/connect/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
        '*demo-api.vivapayments.com/checkout/v2/transactions/*' => Http::response([], 500),
    ]);
    palVivaPost($account, 'm')->assertOk();
    (new HandleVivaWebhookJob($account->id, 'order_pal', null, 'm2', 1796))->handle();

    expect(PaymentAttempt::count())->toBe(0);
});

it('logs a late Viva event for a completed payment through the controller and dedupes a repeat', function () {
    $account = VivaAccount::factory()->create();
    $payment = Payment::factory()->viva()->create(['viva_account_id' => $account->id, 'viva_order_code' => 'order_pal', 'status' => 'completed']);

    palVivaFake('E', 'E');
    palVivaPost($account, 'late_1')->assertOk();
    (new HandleVivaWebhookJob($account->id, 'order_pal', 'txn_pal', 'late_1', 1796))->handle();

    expect($payment->paymentAttempts()->count())->toBe(1)->and($payment->fresh()->status)->toBe('completed');
});

// ---- AC-8: Whop ----

it('logs Whop payment.succeeded and payment.failed, keeps the status rule, and ignores refunds', function () {
    Queue::fake();
    $account = WhopAccount::factory()->create();
    $payment = Payment::factory()->whop()->create(['whop_account_id' => $account->id, 'amount' => 1234, 'currency' => 'gbp', 'status' => 'pending']);

    palWhopJob($account, 'w1', 'payment.failed', $payment, ['failure_code' => 'card_declined', 'failure_message' => 'Declined']);
    expect($payment->fresh()->status)->toBe('pending');
    palWhopJob($account, 'w2', 'refund.created', $payment);
    palWhopJob($account, 'w3', 'payment.succeeded', $payment);

    $rows = $payment->paymentAttempts()->orderBy('sequence')->get();
    expect($rows->pluck('status')->all())->toBe(['failed', 'succeeded'])
        ->and($rows[0]->error_code)->toBe('card_declined')->and($rows[0]->reason)->toBe('Declined')
        ->and($rows[0]->reference)->toBe('pay_whop_1')->and($rows[0]->amount)->toBe(1234)
        ->and($payment->fresh()->status)->toBe('completed');
});

it('does not log Whop events with no uuid, an unknown uuid or another accounts payment, and dedupes a rerun', function () {
    Queue::fake();
    $account = WhopAccount::factory()->create();
    $otherAccount = WhopAccount::factory()->create();
    $payment = Payment::factory()->whop()->create(['whop_account_id' => $otherAccount->id, 'amount' => 1234, 'currency' => 'gbp']);
    $mine = Payment::factory()->whop()->create(['whop_account_id' => $account->id, 'amount' => 1234, 'currency' => 'gbp']);

    (new HandleWhopWebhookJob($account->id, 'n1', json_encode(['type' => 'payment.failed', 'data' => ['id' => 'p']])))->handle();
    (new HandleWhopWebhookJob($account->id, 'n2', json_encode(['type' => 'payment.failed', 'data' => ['id' => 'p', 'metadata' => ['payment_id' => 'nope']]])))->handle();
    palWhopJob($account, 'n3', 'payment.failed', $payment);
    expect(PaymentAttempt::count())->toBe(0);

    palWhopJob($account, 'n4', 'payment.failed', $mine);
    palWhopJob($account, 'n4', 'payment.failed', $mine);
    expect($mine->paymentAttempts()->count())->toBe(1);
});

it('logs a Whop success that fails the amount check, with the reported amount, and does not complete it', function () {
    Queue::fake();
    $account = WhopAccount::factory()->create();
    $payment = Payment::factory()->whop()->create(['whop_account_id' => $account->id, 'amount' => 9999, 'currency' => 'gbp', 'status' => 'pending']);

    palWhopJob($account, 'm1', 'payment.succeeded', $payment); // event says 12.34

    $row = $payment->paymentAttempts()->sole();
    expect($row->amount)->toBe(1234)->and($row->amount_reported)->toBeTrue()
        ->and($payment->fresh()->status)->toBe('pending');
});

// ---- AC-2 / AC-3: logger behaviour ----

it('never throws, and a webhook still returns 200 and the status still changes, when logging breaks', function () {
    [$account, $payment] = palStripe('x', 'x');
    Schema::drop('payment_attempts');

    palStripePost($account, 'payment_intent.succeeded', 'evt_broken')->assertOk();

    expect($payment->fresh()->status)->toBe('completed');
    expect(app(PaymentAttemptLogger::class)->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_pal'], ['type' => 'payment_intent.succeeded', 'event_id' => 'x']))->toBeNull();
});

class PalThrowingExtractor implements AttemptExtractor
{
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData
    {
        throw new RuntimeException('boom');
    }
}

it('survives an extractor that throws', function () {
    $payment = Payment::factory()->create();
    config(['payment_attempts.extractors.stripe' => PalThrowingExtractor::class]);

    expect(app(PaymentAttemptLogger::class)->record(PaymentProvider::Stripe, $payment, [], []))->toBeNull();
    expect(PaymentAttempt::count())->toBe(0);
});

it('returns the existing row for a repeated event key and retries a sequence clash', function () {
    $payment = Payment::factory()->create();
    $logger = app(PaymentAttemptLogger::class);
    $ctx = fn (string $id) => ['type' => 'payment_intent.payment_failed', 'event_id' => $id, 'created' => 1790000000];

    $first = $logger->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_1', 'amount' => 100, 'currency' => 'usd'], $ctx('evt_1'));
    $again = $logger->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_1', 'amount' => 100, 'currency' => 'usd'], $ctx('evt_1'));
    $next = $logger->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_1', 'amount' => 100, 'currency' => 'usd'], $ctx('evt_2'));

    expect($again->id)->toBe($first->id)->and($next->sequence)->toBe(2)->and(PaymentAttempt::count())->toBe(2);
});

/**
 * A logger whose transaction loses a race: the first $failures calls throw a unique key violation,
 * after running $beforeThrow (the "other writer" committing its row). Later calls run the real code.
 */
function palRacingLogger(int $failures, ?Closure $beforeThrow = null): PaymentAttemptLogger
{
    return new class(app(AttemptPayloadSanitizer::class), $failures, $beforeThrow) extends PaymentAttemptLogger
    {
        public int $calls = 0;

        public function __construct(AttemptPayloadSanitizer $sanitizer, private int $failures, private ?Closure $beforeThrow)
        {
            parent::__construct($sanitizer);
        }

        protected function insertInTransaction(array $attributes, PaymentProvider $provider, Payment $payment): PaymentAttempt
        {
            $this->calls++;

            if ($this->calls <= $this->failures) {
                if ($this->beforeThrow !== null) {
                    ($this->beforeThrow)($attributes);
                }

                throw new UniqueConstraintViolationException('mysql', 'insert into payment_attempts', [], new Exception('Duplicate entry'));
            }

            return parent::insertInTransaction($attributes, $provider, $payment);
        }
    };
}

it('returns the existing row when a parallel writer wins the same event key', function () {
    $payment = Payment::factory()->create();
    $winner = null;
    $logger = palRacingLogger(1, function (array $attributes) use (&$winner) {
        $winner = PaymentAttempt::create($attributes + ['sequence' => 1]);
    });

    $row = $logger->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_1', 'amount' => 100, 'currency' => 'usd'], ['type' => 'payment_intent.succeeded', 'event_id' => 'evt_race', 'created' => 1790000000]);

    expect($row)->not->toBeNull()
        ->and($row->id)->toBe($winner->id)
        ->and($logger->calls)->toBe(1)
        ->and(PaymentAttempt::count())->toBe(1);
});

it('retries once with a fresh sequence when a different event took the same number', function () {
    $payment = Payment::factory()->create();
    $logger = palRacingLogger(1, function (array $attributes) {
        // A different event (other key) grabs sequence 1 first.
        PaymentAttempt::create(array_merge($attributes, ['event_key' => 'evt_other', 'sequence' => 1]));
    });

    $row = $logger->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_1', 'amount' => 100, 'currency' => 'usd'], ['type' => 'payment_intent.payment_failed', 'event_id' => 'evt_mine', 'created' => 1790000000]);

    expect($row)->not->toBeNull()
        ->and($row->event_key)->toBe('evt_mine')
        ->and($row->sequence)->toBe(2)
        ->and($logger->calls)->toBe(2)
        ->and(PaymentAttempt::count())->toBe(2);
});

it('gives up after one retry and never throws when the clash keeps happening', function () {
    $payment = Payment::factory()->create();
    $logger = palRacingLogger(5);

    $row = $logger->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_1', 'amount' => 100, 'currency' => 'usd'], ['type' => 'payment_intent.succeeded', 'event_id' => 'evt_stuck', 'created' => 1790000000]);

    expect($row)->toBeNull()
        ->and($logger->calls)->toBe(2)
        ->and(PaymentAttempt::count())->toBe(0);
});

it('stores the event time as the same moment when the app timezone is not UTC', function () {
    config(['app.timezone' => 'Asia/Karachi']);
    date_default_timezone_set('Asia/Karachi');
    $payment = Payment::factory()->create();

    $row = app(PaymentAttemptLogger::class)->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_tz', 'amount' => 100, 'currency' => 'usd'], ['type' => 'payment_intent.succeeded', 'event_id' => 'evt_tz', 'created' => 1790000000]);

    expect($row->fresh()->occurred_at->getTimestamp())->toBe(1790000000)
        ->and(DB::table('payment_attempts')->where('id', $row->id)->value('occurred_at'))
        ->toBe(CarbonImmutable::createFromTimestamp(1790000000, 'Asia/Karachi')->format('Y-m-d H:i:s'));

    date_default_timezone_set('UTC');
});

it('does not log for a soft deleted payment', function () {
    $payment = Payment::factory()->create(['stripe_payment_intent_id' => 'pi_del']);
    $payment->delete();

    $row = app(PaymentAttemptLogger::class)->record(PaymentProvider::Stripe, $payment, ['id' => 'pi_del'], ['type' => 'payment_intent.succeeded', 'event_id' => 'e']);

    expect($row)->toBeNull()->and(PaymentAttempt::count())->toBe(0);
});

// ---- AC-10: secrets ----

it('stores no card numbers, CVV, client secrets, tokens or API keys in any column', function () {
    Queue::fake();
    [$account, $payment] = palStripe('x', 'x');

    palStripePost($account, 'payment_intent.payment_failed', 'evt_sec', [
        'client_secret' => 'pi_pal_secret_ZZZ',
        'last_payment_error' => [
            'code' => 'card_declined', 'decline_code' => '4242 4242 4242 4242',
            'message' => 'Card 4111-1111-1111-1111 was declined',
            'payment_method' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'number' => '4242424242424242', 'cvc' => '123', 'api_key' => 'sk_live_abc', 'access_token' => 'tok_abc']],
        ],
    ])->assertOk();

    $dump = json_encode(PaymentAttempt::all()->toArray());
    foreach (['4242424242424242', '4242 4242 4242 4242', '4111-1111-1111-1111', 'pi_pal_secret_ZZZ', 'sk_live_abc', 'tok_abc', '"cvc"'] as $needle) {
        expect($dump)->not->toContain($needle);
    }
    expect($dump)->toContain('4242'); // last4 survives
});

it('sanitizer drops secret keys, keeps company and reference_number, masks digits, caps size and depth', function () {
    $s = app(AttemptPayloadSanitizer::class);

    $out = $s->sanitize([
        'company' => 'Acme', 'reference_number' => 'R-1', 'expand' => 'x', 'pan' => '1', 'number' => '2', 'card_number' => '3',
        'Authorization' => 'Bearer x', 'x_signature' => 's', 'cvv' => '1', 'note' => str_repeat('a', 600),
        'text' => 'pay 4111 1111 1111 1111 now',
        'deep' => ['a' => ['b' => ['c' => ['d' => 'gone']]]],
    ]);

    expect(array_keys($out))->toBe(['company', 'reference_number', 'expand', 'note', 'text', 'deep'])
        ->and(strlen($out['note']))->toBe(500)
        ->and($out['text'])->toBe('pay [masked] now')
        ->and($out['deep']['a']['b'])->toBe([]);
    expect($s->sanitize(['big' => str_repeat('x', 400), 'more' => array_fill(0, 40, str_repeat('y', 400))]))->toBe(['truncated' => true]);
    expect($s->sanitize(null))->toBeNull()->and($s->text('order 5555555555554444'))->toBe('order [masked]');
});

// ---- AC-11: visibility ----

function palShowProps(User $user, Payment $payment): array
{
    $page = test()->actingAs($user)->get(route('payments.show', $payment))->assertOk();
    $props = [];
    $page->assertInertia(function (AssertableInertia $inertia) use (&$props) {
        $props = $inertia->toArray()['props'];
    });

    return $props;
}

it('sends attemptLog to admins only, newest first, and null for agents, accounts and providers with no extractor', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create()->assignRole('agent');
    $accountUser = User::factory()->create()->assignRole('account');
    $payment = Payment::factory()->create(['user_id' => $agent->id]);
    $accountPayment = Payment::factory()->create(['user_id' => $accountUser->id]);
    PaymentAttempt::factory()->create(['payment_id' => $payment->id, 'sequence' => 1, 'event_key' => 'a', 'status' => 'failed']);
    PaymentAttempt::factory()->create(['payment_id' => $accountPayment->id, 'sequence' => 1, 'event_key' => 'c']);
    PaymentAttempt::factory()->create(['payment_id' => $payment->id, 'sequence' => 2, 'event_key' => 'b']);

    $log = palShowProps($admin, $payment)['attemptLog'];
    expect($log['provider'])->toBe('stripe')
        ->and(array_column($log['rows'], 'sequence'))->toBe([2, 1]);
    expect(palShowProps($agent, $payment)['attemptLog'])->toBeNull();
    expect(palShowProps($accountUser, $accountPayment)['attemptLog'])->toBeNull();

    $mpgs = Payment::factory()->mpgs()->create();
    expect(palShowProps($admin, $mpgs)['attemptLog'])->toBeNull();
});

it('tells the payment page who may start a new payment', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $agent = User::factory()->create()->assignRole('agent');
    $accountUser = User::factory()->create()->assignRole('account');
    $payment = Payment::factory()->create(['user_id' => $agent->id]);
    $accountPayment = Payment::factory()->create(['user_id' => $accountUser->id]);

    expect(palShowProps($admin, $payment)['canCreate'])->toBeTrue()
        ->and(palShowProps($agent, $payment)['canCreate'])->toBeTrue()
        ->and(palShowProps($accountUser, $accountPayment)['canCreate'])->toBeFalse();
});

it('gives an empty log for a supported provider with no rows', function () {
    $admin = User::factory()->create()->assignRole('admin');

    expect(palShowProps($admin, Payment::factory()->create())['attemptLog'])->toBe(['provider' => 'stripe', 'rows' => []]);
});

// ---- AC-15: extensibility ----

class PalFakeExtractor implements AttemptExtractor
{
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData
    {
        return new AttemptData(event: 'FAKE_FAILED', status: 'failed', eventKey: $payload['id'], reference: 'fake_ref', reason: 'Fake reason');
    }
}

it('lets a new provider join with one extractor and one config line, with no migration or Vue change', function () {
    config(['payment_attempts.extractors.clover' => PalFakeExtractor::class]);
    $admin = User::factory()->create()->assignRole('admin');
    $payment = Payment::factory()->clover()->create();

    $row = app(PaymentAttemptLogger::class)->record(PaymentProvider::Clover, $payment, ['id' => 'f1']);

    expect($row->reason)->toBe('Fake reason')->and($row->amount)->toBe((int) $payment->amount)->and($row->amount_reported)->toBeFalse();
    $log = palShowProps($admin, $payment)['attemptLog'];
    expect($log['provider'])->toBe('clover')->and($log['rows'])->toHaveCount(1);
});
