<?php

use App\Jobs\HandleAuthorizenetWebhookJob;
use App\Jobs\SendPaymentNotification;
use App\Models\AuthorizenetAccount;
use App\Models\Payment;
use App\Models\User;
use App\Services\Authorizenet\AuthorizenetChargeClassifier;
use App\Services\Authorizenet\AuthorizenetInvoice;
use App\Services\Authorizenet\AuthorizenetInvoiceLookup;
use App\Services\Authorizenet\AuthorizenetStalePaymentSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake([SendPaymentNotification::class]);
});

function anetPayment(array $attributes = []): Payment
{
    return Payment::factory()->authorizenet()->create(array_merge([
        'amount' => 1234,
        'currency' => 'usd',
        'status' => 'pending',
        'authorizenet_token_issued_at' => now()->subMinute(),
        'authorizenet_token_last_issued_at' => now()->subMinute(),
    ], $attributes));
}

function anetTx(Payment $payment, array $overrides = []): array
{
    return array_replace_recursive([
        'transId' => '60000001',
        'responseCode' => 1,
        'transactionStatus' => 'capturedPendingSettlement',
        'authAmount' => 12.34,
        'order' => ['invoiceNumber' => AuthorizenetInvoice::for($payment)],
    ], $overrides);
}

/**
 * Fake the gateway: a list result (rows keyed to the payment) plus a details result per transId.
 *
 * @param  array<int, array<string, mixed>>  $rows
 * @param  array<string, array<string, mixed>>  $details
 */
function fakeAnet(array $rows = [], array $details = []): void
{
    Http::fake(function (Request $request) use ($rows, $details) {
        $body = $request->data();

        if (isset($body['getUnsettledTransactionListRequest'])) {
            return Http::response("\xEF\xBB\xBF".json_encode([
                'transactions' => $rows,
                'messages' => ['resultCode' => 'Ok'],
            ]), 200);
        }

        if (isset($body['getTransactionDetailsRequest'])) {
            $tx = $details[$body['getTransactionDetailsRequest']['transId']] ?? null;

            return Http::response(json_encode($tx
                ? ['transaction' => $tx, 'messages' => ['resultCode' => 'Ok']]
                : ['messages' => ['resultCode' => 'Error']]), $tx ? 200 : 404);
        }

        if (isset($body['getHostedPaymentPageRequest'])) {
            return Http::response("\xEF\xBB\xBF".json_encode(['token' => 'tok-abc', 'messages' => ['resultCode' => 'Ok']]), 200);
        }

        return Http::response('{}', 500);
    });
}

function listRow(Payment $payment, string $transId = '60000001', string $status = 'capturedPendingSettlement'): array
{
    return [
        'transId' => $transId,
        'submitTimeUTC' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        'transactionStatus' => $status,
        'invoiceNumber' => AuthorizenetInvoice::for($payment),
    ];
}

it('builds a 20 character invoice number from the id and uuid', function () {
    $payment = anetPayment();

    $invoice = AuthorizenetInvoice::for($payment);

    expect(strlen($invoice))->toBeLessThanOrEqual(20)
        ->and($invoice)->toStartWith(str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT))
        ->and($invoice)->toEndWith(substr(str_replace('-', '', $payment->uuid), 0, 8));
});

it('converts amounts in integer cents without float noise', function () {
    expect(AuthorizenetInvoice::centsToDecimal(1234))->toBe('12.34')
        ->and(AuthorizenetInvoice::centsToDecimal(5))->toBe('0.05')
        ->and(AuthorizenetInvoice::toCents(12.34))->toBe(1234)
        ->and(AuthorizenetInvoice::toCents(0.1 + 0.2))->toBe(30)
        ->and(AuthorizenetInvoice::toCents('abc'))->toBeNull();
});

it('classifies transactions into the four buckets', function (array $overrides, string $expected) {
    $payment = anetPayment();

    expect(AuthorizenetChargeClassifier::classify(anetTx($payment, $overrides), $payment))->toBe($expected);
})->with([
    'approved' => [[], AuthorizenetChargeClassifier::APPROVED],
    'approved settled' => [['transactionStatus' => 'settledSuccessfully'], AuthorizenetChargeClassifier::APPROVED],
    'wrong amount' => [['authAmount' => 12.35], AuthorizenetChargeClassifier::UNKNOWN],
    'wrong invoice' => [['order' => ['invoiceNumber' => '999999deadbeef']], AuthorizenetChargeClassifier::UNKNOWN],
    'held by code' => [['responseCode' => 4, 'transactionStatus' => 'weird'], AuthorizenetChargeClassifier::HELD],
    'held by status' => [['responseCode' => 1, 'transactionStatus' => 'FDSPendingReview'], AuthorizenetChargeClassifier::HELD],
    'declined' => [['responseCode' => 2, 'transactionStatus' => 'declined'], AuthorizenetChargeClassifier::DECLINED],
    'error code' => [['responseCode' => 3, 'transactionStatus' => 'generalError'], AuthorizenetChargeClassifier::UNKNOWN],
    'voided' => [['transactionStatus' => 'voided'], AuthorizenetChargeClassifier::UNKNOWN],
]);

it('creates a token from the payment record and stamps the issue times', function () {
    fakeAnet();
    $payment = anetPayment(['authorizenet_token_issued_at' => null, 'authorizenet_token_last_issued_at' => null, 'client_name' => 'Jane']);

    $this->postJson(route('pay.authorizenet.token', $payment))
        ->assertOk()
        ->assertJson(['token' => 'tok-abc'])
        ->assertJsonPath('form_url', 'https://test.authorize.net/payment/payment');

    Http::assertSent(function (Request $request) use ($payment) {
        $body = $request->data()['getHostedPaymentPageRequest'] ?? null;

        return $body !== null
            && $body['transactionRequest']['amount'] === '12.34'
            && $body['transactionRequest']['order']['invoiceNumber'] === AuthorizenetInvoice::for($payment)
            && $body['transactionRequest']['billTo'] === ['firstName' => 'Jane', 'lastName' => 'Jane']
            && str_starts_with(json_decode(collect($body['hostedPaymentSettings']['setting'])
                ->firstWhere('settingName', 'hostedPaymentIFrameCommunicatorUrl')['settingValue'], true)['url'], 'https://');
    });

    $payment->refresh();
    expect($payment->authorizenet_token_issued_at)->not->toBeNull()
        ->and($payment->authorizenet_token_last_issued_at)->not->toBeNull();
});

it('refuses a token for a payment that cannot be paid, with no gateway call', function (array $attributes) {
    fakeAnet();
    $payment = anetPayment($attributes);

    $this->postJson(route('pay.authorizenet.token', $payment))->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'completed' => [['status' => 'completed']],
    'transaction already exists' => [['authorizenet_transaction_id' => '60000001']],
]);

it('refuses a token for an inactive account or another provider', function () {
    fakeAnet();
    $inactive = anetPayment();
    $inactive->authorizenetAccount->update(['is_active' => false]);
    $stripe = Payment::factory()->create(['status' => 'pending']);

    $this->postJson(route('pay.authorizenet.token', $inactive))->assertStatus(422);
    $this->postJson(route('pay.authorizenet.token', $stripe))->assertStatus(422);

    Http::assertNothingSent();
});

it('returns 502 when the gateway rejects the token request', function () {
    Http::fake(['*' => Http::response(json_encode(['messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00013', 'text' => 'bad']]]]), 200)]);
    $payment = anetPayment(['authorizenet_token_issued_at' => null]);

    $this->postJson(route('pay.authorizenet.token', $payment))
        ->assertStatus(502)
        ->assertJsonMissing(['text' => 'bad']);

    expect($payment->fresh()->authorizenet_token_issued_at)->toBeNull();
});

it('limits token requests per payment to 20 an hour', function () {
    fakeAnet();
    $payment = anetPayment(['authorizenet_token_issued_at' => null]);

    foreach (range(1, 20) as $i) {
        $this->postJson(route('pay.authorizenet.token', $payment))->assertOk();
    }

    $this->postJson(route('pay.authorizenet.token', $payment))->assertStatus(429);
});

it('completes the payment from the status poll with no browser callback at all', function () {
    $payment = anetPayment();
    fakeAnet([listRow($payment)], ['60000001' => anetTx($payment)]);

    $this->getJson(route('pay.authorizenet.status', $payment))
        ->assertOk()
        ->assertJson(['status' => 'completed', 'state' => 'completed']);

    $payment->refresh();
    expect($payment->status)->toBe('completed')
        ->and($payment->authorizenet_transaction_id)->toBe('60000001')
        ->and($payment->paid_at)->not->toBeNull();
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('does not complete on a wrong amount or a foreign invoice', function (array $overrides) {
    $payment = anetPayment();
    fakeAnet([listRow($payment)], ['60000001' => anetTx($payment, $overrides)]);

    $this->getJson(route('pay.authorizenet.status', $payment))->assertJson(['state' => 'waiting']);

    expect($payment->fresh()->status)->toBe('pending');
    Bus::assertNotDispatched(SendPaymentNotification::class);
})->with([
    'wrong amount' => [['authAmount' => 1.0]],
    'foreign invoice' => [['order' => ['invoiceNumber' => '000999abcdef0123']]],
]);

it('keeps a held transaction pending and reports held, then completes when approved', function () {
    $payment = anetPayment();
    fakeAnet([listRow($payment, '60000001', 'FDSPendingReview')], [
        '60000001' => anetTx($payment, ['responseCode' => 4, 'transactionStatus' => 'FDSPendingReview']),
    ]);

    $this->getJson(route('pay.authorizenet.status', $payment))->assertJson(['status' => 'pending', 'state' => 'held']);
    expect($payment->fresh()->authorizenet_transaction_id)->toBe('60000001');

    Cache::flush();
    Http::swap(new Factory);
    fakeAnet([], ['60000001' => anetTx($payment)]);

    $this->getJson(route('pay.authorizenet.status', $payment))->assertJson(['state' => 'completed']);
});

it('completes a payment once when two approved transactions share an invoice', function () {
    $payment = anetPayment();
    fakeAnet([listRow($payment, '60000001'), listRow($payment, '60000002')], [
        '60000001' => anetTx($payment),
        '60000002' => anetTx($payment, ['transId' => '60000002']),
    ]);

    $this->getJson(route('pay.authorizenet.status', $payment))->assertJson(['state' => 'completed']);

    expect($payment->fresh()->authorizenet_transaction_id)->toBe('60000001');
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('never lets a later approval change a cancelled payment', function () {
    $payment = anetPayment(['status' => 'cancelled']);
    fakeAnet([listRow($payment)], ['60000001' => anetTx($payment)]);

    $this->getJson(route('pay.authorizenet.status', $payment))->assertJson(['state' => 'unavailable']);

    expect($payment->fresh()->status)->toBe('cancelled');
});

it('runs no lookup without a token, with an old token, or inside the throttle window', function () {
    $payment = anetPayment(['authorizenet_token_issued_at' => null]);
    $old = anetPayment(['authorizenet_token_issued_at' => now()->subMinutes(61)]);
    fakeAnet([listRow($payment)], ['60000001' => anetTx($payment)]);

    $this->getJson(route('pay.authorizenet.status', $payment))->assertJson(['state' => 'waiting']);
    $this->getJson(route('pay.authorizenet.status', $old))->assertJson(['state' => 'waiting']);
    Http::assertNothingSent();

    $live = anetPayment();
    foreach (range(1, 10) as $i) {
        $this->getJson(route('pay.authorizenet.status', $live));
    }
    Http::assertSentCount(1); // ten polls in 5 seconds make one list call
});

it('stops paging on a short page and treats a missing transactions key as zero rows', function () {
    $payment = anetPayment();
    Http::fake(['*' => Http::response(json_encode(['messages' => ['resultCode' => 'Ok']]), 200)]);

    $this->getJson(route('pay.authorizenet.status', $payment))->assertJson(['state' => 'waiting']);

    Http::assertSentCount(1);
});

it('redirects a returning client to the success page when it finished at the gateway', function () {
    $payment = anetPayment();
    fakeAnet([listRow($payment)], ['60000001' => anetTx($payment)]);

    $this->get(route('pay.show', $payment))->assertRedirect(route('pay.success', $payment));
});

it('widens the CSP only on the Authorize.net pay page and relaxes framing only on the communicator', function () {
    fakeAnet();
    $payment = anetPayment();
    $other = Payment::factory()->create(['status' => 'pending']);

    $csp = $this->get(route('pay.show', $payment))->headers->get('Content-Security-Policy');
    expect($csp)->toContain('frame-src')->toContain('https://test.authorize.net')
        ->and($csp)->toContain("form-action 'self' https://test.authorize.net");

    $stripeCsp = $this->get(route('pay.show', $other))->headers->get('Content-Security-Policy');
    expect($stripeCsp)->not->toContain('authorize.net')->not->toContain('form-action');

    $communicator = $this->get(route('pay.authorizenet.communicator'));
    expect($communicator->headers->get('X-Frame-Options'))->toBeNull()
        ->and($communicator->headers->get('Content-Security-Policy'))->toContain('frame-ancestors');
});

it('rejects an edit of amount, currency or account once a token was issued', function () {
    $payment = anetPayment();
    $admin = User::factory()->create(['email_verified_at' => now()]);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin->assignRole('admin');

    $this->actingAs($admin)->patch(route('payments.update', $payment), [
        'brand_id' => $payment->brand_id,
        'relationship_manager_id' => $payment->relationship_manager_id,
        'provider' => 'authorizenet',
        'account_id' => $payment->authorizenet_account_id,
        'currency' => 'usd',
        'amount' => '99.00',
    ])->assertSessionHasErrors('amount');

    expect($payment->fresh()->amount)->toBe(1234);
});

describe('webhook', function () {
    function signedWebhook(Payment $payment, array $body, ?string $key = 'sig-key'): array
    {
        $raw = json_encode($body);
        $payment->authorizenetAccount->forceFill(['signature_key' => $key])->save();

        return [$raw, ['X-ANET-Signature' => 'sha512='.strtoupper(hash_hmac('sha512', $raw, (string) $key)), 'Content-Type' => 'application/json']];
    }

    function webhookBody(string $notificationId = 'n-1', string $event = 'net.authorize.payment.authcapture.created'): array
    {
        return ['notificationId' => $notificationId, 'eventType' => $event, 'payload' => ['id' => '60000001']];
    }

    it('rejects a wrong signature and an account with no signature key', function () {
        Queue::fake();
        $payment = anetPayment();
        [$raw, $headers] = signedWebhook($payment, webhookBody());
        $url = route('webhook.authorizenet', $payment->authorizenet_account_id);

        $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars(['X-ANET-Signature' => 'sha512=bad']), $raw)->assertStatus(401);

        $payment->authorizenetAccount->forceFill(['signature_key' => null])->save();
        $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars($headers), $raw)->assertStatus(403);

        Queue::assertNothingPushed();
    });

    it('queues a job for a signed delivery, once per notification id', function () {
        Queue::fake();
        $payment = anetPayment();
        [$raw, $headers] = signedWebhook($payment, webhookBody());
        $url = route('webhook.authorizenet', $payment->authorizenet_account_id);
        $server = $this->transformHeadersToServerVars($headers);

        $this->call('POST', $url, [], [], [], $server, $raw)->assertOk();
        $this->call('POST', $url, [], [], [], $server, $raw)->assertOk();

        Queue::assertPushed(HandleAuthorizenetWebhookJob::class, 1);
    });

    it('completes the payment from the job by reading the transaction, never the body', function () {
        $payment = anetPayment();
        fakeAnet([], ['60000001' => anetTx($payment)]);

        (new HandleAuthorizenetWebhookJob($payment->authorizenet_account_id, '60000001'))
            ->handle(app(AuthorizenetInvoiceLookup::class));

        expect($payment->fresh()->status)->toBe('completed');
    });

    it('drops a transaction that belongs to a payment of another account', function () {
        $payment = anetPayment();
        $otherAccount = AuthorizenetAccount::factory()->create();
        fakeAnet([], ['60000001' => anetTx($payment)]);

        (new HandleAuthorizenetWebhookJob($otherAccount->id, '60000001'))
            ->handle(app(AuthorizenetInvoiceLookup::class));

        expect($payment->fresh()->status)->toBe('pending');
    });

    it('fails a held payment when the transaction is later declined', function () {
        $payment = anetPayment(['authorizenet_transaction_id' => '60000001']);
        fakeAnet([], ['60000001' => anetTx($payment, ['responseCode' => 2, 'transactionStatus' => 'declined'])]);

        (new HandleAuthorizenetWebhookJob($payment->authorizenet_account_id, '60000001'))
            ->handle(app(AuthorizenetInvoiceLookup::class));

        expect($payment->fresh()->status)->toBe('failed');
    });
});

describe('sweep', function () {
    it('resolves pending payments by invoice number in one list pass and reads known ones directly', function () {
        $byInvoice = anetPayment();
        $known = anetPayment(['authorizenet_account_id' => $byInvoice->authorizenet_account_id, 'authorizenet_transaction_id' => '60000009']);
        $tooOld = anetPayment(['authorizenet_token_last_issued_at' => now()->subDays(2), 'authorizenet_token_issued_at' => now()->subDays(2)]);

        fakeAnet([listRow($byInvoice, '60000001')], [
            '60000001' => anetTx($byInvoice),
            '60000009' => anetTx($known, ['transId' => '60000009']),
        ]);

        (new AuthorizenetStalePaymentSweep)();

        expect($byInvoice->fresh()->status)->toBe('completed')
            ->and($known->fresh()->status)->toBe('completed')
            ->and($tooOld->fresh()->status)->toBe('pending');
    });

    it('keeps going when one account fails', function () {
        $bad = anetPayment();
        $good = anetPayment();
        $bad->authorizenetAccount->update(['api_login_id' => 'bad-login']);

        Http::fake(function (Request $request) use ($good) {
            $auth = collect($request->data())->first()['merchantAuthentication']['name'] ?? '';
            if ($auth === 'bad-login') {
                throw new RuntimeException('boom');
            }

            return isset($request->data()['getUnsettledTransactionListRequest'])
                ? Http::response(json_encode(['transactions' => [listRow($good)], 'messages' => ['resultCode' => 'Ok']]), 200)
                : Http::response(json_encode(['transaction' => anetTx($good), 'messages' => ['resultCode' => 'Ok']]), 200);
        });

        (new AuthorizenetStalePaymentSweep)();

        expect($good->fresh()->status)->toBe('completed')
            ->and($bad->fresh()->status)->toBe('pending');
    });
});
