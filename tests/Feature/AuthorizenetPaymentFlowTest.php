<?php

use App\Jobs\HandleAuthorizenetWebhookJob;
use App\Jobs\ResolveAuthorizenetCharge;
use App\Jobs\SendPaymentNotification;
use App\Models\AuthorizenetAccount;
use App\Models\Payment;
use App\Models\User;
use App\Services\Authorizenet\AuthorizenetChargeClassifier;
use App\Services\Authorizenet\AuthorizenetChargeReply;
use App\Services\Authorizenet\AuthorizenetInvoice;
use App\Services\Authorizenet\AuthorizenetInvoiceLookup;
use App\Services\Authorizenet\AuthorizenetStalePaymentSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        'authorizenet_first_attempt_at' => now()->subMinute(),
        'authorizenet_last_attempt_at' => now()->subMinute(),
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
function fakeAnet(array $rows = [], array $details = [], array|string|null $charge = null): void
{
    app()->forgetInstance(Factory::class); // a fresh fake replaces any earlier one in the same test
    Facade::clearResolvedInstance(Factory::class);

    Http::fake(function (Request $request) use ($rows, $details, $charge) {
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

        if (isset($body['createTransactionRequest'])) {
            if ($charge === 'timeout') {
                throw new ConnectionException('timed out');
            }

            return Http::response("\xEF\xBB\xBF".json_encode($charge ?? []), $charge === null ? 500 : 200);
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

it('uses the prefixed reference code as the invoice number', function () {
    $payment = anetPayment(['reference_code' => 1020]);
    $payment->authorizenetAccount->forceFill(['prefix' => 'AUTH'])->save();

    expect(AuthorizenetInvoice::for($payment->fresh()))->toBe('AUTH-001020');
});

it('uses the bare reference code when the account has no prefix', function () {
    $payment = anetPayment(['reference_code' => 1020]);
    $payment->authorizenetAccount->forceFill(['prefix' => null])->save();

    expect(AuthorizenetInvoice::for($payment->fresh()))->toBe('001020');
});

it('keeps the longest prefixed invoice number inside the 20 character limit', function () {
    $payment = anetPayment(['reference_code' => 1234567]);
    $payment->authorizenetAccount->forceFill(['prefix' => 'ABCDEFGHIJ'])->save();

    expect(strlen(AuthorizenetInvoice::for($payment->fresh())))->toBeLessThanOrEqual(AuthorizenetInvoice::MAX_LENGTH);
});

it('still accepts the legacy id and uuid invoice number', function () {
    $payment = anetPayment();
    $legacy = AuthorizenetInvoice::legacy($payment);

    expect(strlen($legacy))->toBeLessThanOrEqual(20)
        ->and($legacy)->toStartWith(str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT))
        ->and(AuthorizenetInvoice::matches($payment, $legacy))->toBeTrue()
        ->and(AuthorizenetInvoice::matches($payment, AuthorizenetInvoice::for($payment)))->toBeTrue()
        ->and(AuthorizenetInvoice::matches($payment, '999999deadbeef'))->toBeFalse()
        ->and(AuthorizenetInvoice::matches($payment, null))->toBeFalse();
});

it('reads the reference number out of an invoice number', function (string $invoice, ?int $expected) {
    expect(AuthorizenetInvoice::referenceFromInvoice($invoice))->toBe($expected);
})->with([
    'prefixed' => ['AUTH-001020', 1020],
    'no prefix' => ['001020', 1020],
    'seven digits' => ['PRISM-1234567', 1234567],
    'lowercase prefix' => ['auth-001020', null],
    'junk' => ['not an invoice', null],
]);

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

/** A createTransaction reply body. */
function chargeReply(string $code, ?string $transId = '60000001', ?string $errorCode = null): array
{
    return [
        'transactionResponse' => array_filter([
            'responseCode' => $code,
            'transId' => $transId,
            'errors' => $errorCode ? [['errorCode' => $errorCode, 'errorText' => 'gateway text']] : null,
        ]),
        'messages' => ['resultCode' => $code === '1' || $code === '4' ? 'Ok' : 'Error'],
    ];
}

function chargeBody(array $overrides = []): array
{
    return array_merge([
        'data_descriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
        'data_value' => 'opaque-code-from-acceptjs',
        'postal_code' => '90210',
    ], $overrides);
}

it('charges the one time code with the amount from the record and completes after the read back', function () {
    $payment = anetPayment(['authorizenet_first_attempt_at' => null, 'authorizenet_last_attempt_at' => null, 'client_name' => 'Jane']);
    fakeAnet([], ['60000001' => anetTx($payment)], chargeReply('1'));

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody(['amount' => 1, 'account_id' => 99]))
        ->assertOk()
        ->assertJson(['outcome' => 'approved']);

    Http::assertSent(function (Request $request) use ($payment) {
        $body = $request->data()['createTransactionRequest'] ?? null;

        return $body !== null
            && $body['transactionRequest']['amount'] === '12.34'
            && $body['transactionRequest']['transactionType'] === 'authCaptureTransaction'
            && $body['refId'] === AuthorizenetInvoice::for($payment)
            && $body['transactionRequest']['order']['invoiceNumber'] === AuthorizenetInvoice::for($payment)
            && $body['transactionRequest']['payment']['opaqueData']['dataValue'] === 'opaque-code-from-acceptjs'
            && $body['transactionRequest']['billTo'] === ['firstName' => 'Jane', 'lastName' => 'Jane', 'zip' => '90210']
            && ! empty($body['transactionRequest']['customerIP'])
            && $body['transactionRequest']['transactionSettings']['setting'][0] === ['settingName' => 'duplicateWindow', 'settingValue' => '300'];
    });

    $payment->refresh();
    expect($payment->status)->toBe('completed')
        ->and($payment->authorizenet_transaction_id)->toBe('60000001')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->authorizenet_first_attempt_at)->not->toBeNull()
        ->and($payment->authorizenet_last_attempt_at)->not->toBeNull();
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('refuses a charge for a payment that cannot be paid, with no gateway call', function (callable $make) {
    fakeAnet();
    $payment = $make();

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'completed' => [fn () => anetPayment(['status' => 'completed'])],
    'transaction already exists' => [fn () => anetPayment(['authorizenet_transaction_id' => '60000001'])],
    'inactive account' => [function () {
        $payment = anetPayment();
        $payment->authorizenetAccount->update(['is_active' => false]);

        return $payment;
    }],
    'no public client key' => [function () {
        $payment = anetPayment();
        $payment->authorizenetAccount->update(['public_client_key' => null]);

        return $payment;
    }],
    'another provider' => [fn () => Payment::factory()->create(['status' => 'pending'])],
]);

it('rejects bad charge input with no gateway call', function (array $overrides) {
    fakeAnet();
    $payment = anetPayment();

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody($overrides))->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'wrong descriptor' => [['data_descriptor' => 'COMMON.OTHER']],
    'no data value' => [['data_value' => '']],
    'huge data value' => [['data_value' => str_repeat('a', 2049)]],
    'bad zip' => [['postal_code' => '<script>']],
    'short zip' => [['postal_code' => '12']],
]);

it('maps each charge reply to an outcome', function (string|array $charge, string $outcome, int $status) {
    $payment = anetPayment();
    fakeAnet([], ['60000001' => anetTx($payment)], $charge);
    Queue::fake();

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())
        ->assertStatus($status)
        ->assertJsonPath('outcome', $outcome)
        ->assertJsonMissingPath('transactionResponse');
})->with([
    'approved' => [chargeReply('1'), 'approved', 200],
    'declined' => [chargeReply('2', '0', '2'), 'declined', 200],
    'check details' => [chargeReply('2', '0', '8'), 'check_details', 200],
    'duplicate' => [chargeReply('3', '0', '11'), 'unclear', 200],
    'other error' => [chargeReply('3', '0', '13'), 'error', 502],
    'timeout' => ['timeout', 'unclear', 200],
    'approved with no transaction id' => [['transactionResponse' => ['responseCode' => '1'], 'messages' => ['resultCode' => 'Ok']], 'unclear', 200],
]);

it('maps replies in the pure reply class', function (array $result, string $outcome) {
    expect(AuthorizenetChargeReply::fromResult($result)->outcome)->toBe($outcome);
})->with([
    '5xx' => [['status' => 503, 'body' => []], AuthorizenetChargeReply::UNCLEAR],
    'bad json' => [['status' => 200, 'body' => []], AuthorizenetChargeReply::UNCLEAR],
    'request rejected' => [['status' => 200, 'body' => ['messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00003']]]]], AuthorizenetChargeReply::ERROR],
    'held' => [['status' => 200, 'body' => chargeReply('4')], AuthorizenetChargeReply::HELD],
    'bad card number (sandbox: response 3, code 6)' => [['status' => 200, 'body' => chargeReply('3', '0', '6')], AuthorizenetChargeReply::CHECK_DETAILS],
    'expired card (sandbox: response 3, code 8)' => [['status' => 200, 'body' => chargeReply('3', '0', '8')], AuthorizenetChargeReply::CHECK_DETAILS],
    'integer response code' => [['status' => 200, 'body' => ['transactionResponse' => ['responseCode' => 1, 'transId' => '5']]], AuthorizenetChargeReply::APPROVED],
]);

it('stores a held transaction, keeps the payment pending and reports held', function () {
    $payment = anetPayment();
    fakeAnet([], ['60000001' => anetTx($payment, ['responseCode' => 4, 'transactionStatus' => 'FDSPendingReview'])], chargeReply('4'));

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())
        ->assertOk()
        ->assertJsonPath('outcome', 'held');

    $payment->refresh();
    expect($payment->status)->toBe('pending')->and($payment->authorizenet_transaction_id)->toBe('60000001');
    Bus::assertNotDispatched(SendPaymentNotification::class);
});

it('treats a read back with the wrong invoice or cents as unclear, never completed', function (array $overrides) {
    $payment = anetPayment();
    fakeAnet([], ['60000001' => anetTx($payment, $overrides)], chargeReply('1'));
    Queue::fake();

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())
        ->assertOk()
        ->assertJsonPath('outcome', 'unclear');

    expect($payment->fresh()->status)->toBe('pending');
})->with([
    'wrong invoice' => [['order' => ['invoiceNumber' => '999999deadbeef']]],
    'wrong cents' => [['authAmount' => 12.35]],
]);

it('treats a failed read back as unclear', function () {
    $payment = anetPayment();
    fakeAnet([], [], chargeReply('1')); // no details for the transaction: the read back 404s
    Queue::fake();

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())
        ->assertOk()
        ->assertJsonPath('outcome', 'unclear');

    expect($payment->fresh()->status)->toBe('pending');
});

it('pauses new charges after an unclear outcome and settles it from the invoice lookup', function () {
    $payment = anetPayment();
    fakeAnet([], [], 'timeout');
    Queue::fake();

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())->assertJsonPath('outcome', 'unclear');
    Queue::assertPushed(ResolveAuthorizenetCharge::class, fn ($job) => $job->paymentId === $payment->id);

    // A second charge during the pause is refused and makes no gateway call.
    Facade::clearResolvedInstance(Factory::class);
    Http::fake();
    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())->assertStatus(409);
    Http::assertNothingSent();

    // The job finds the approved transaction by invoice number and completes the payment once.
    fakeAnet([listRow($payment)], ['60000001' => anetTx($payment)]);
    Cache::flush();
    (new ResolveAuthorizenetCharge($payment->id))->handle(app(AuthorizenetInvoiceLookup::class));
    (new ResolveAuthorizenetCharge($payment->id))->handle(app(AuthorizenetInvoiceLookup::class));

    expect($payment->fresh()->status)->toBe('completed');
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('refuses a second charge while one is in flight', function () {
    fakeAnet();
    $payment = anetPayment();
    $lock = Cache::lock("authorizenet-charge:{$payment->id}", 60);
    $lock->get();

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())->assertStatus(409);

    Http::assertNothingSent();
    $lock->release();
});

it('lets a retry after a decline approve with the same invoice', function () {
    $payment = anetPayment();
    fakeAnet([], [], chargeReply('2', '0', '2'));
    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())->assertJsonPath('outcome', 'declined');
    expect($payment->fresh()->status)->toBe('pending');

    fakeAnet([], ['60000001' => anetTx($payment)], chargeReply('1'));
    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())->assertJsonPath('outcome', 'approved');

    expect($payment->fresh()->status)->toBe('completed');
});

it('limits charges to 5 per payment an hour, with no gateway call', function () {
    fakeAnet([], [], chargeReply('2', '0', '2'));
    $payment = anetPayment();

    foreach (range(1, 5) as $i) {
        $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())->assertOk();
    }

    Http::fake();
    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())
        ->assertStatus(429)
        ->assertJson(['message' => 'Too many attempts. Please try again later.']);
    Http::assertNothingSent();
});

it('limits charges to 20 per IP an hour across payments', function () {
    fakeAnet([], [], chargeReply('2', '0', '2'));

    foreach (range(1, 20) as $i) {
        $this->postJson(route('pay.authorizenet.charge', anetPayment()), chargeBody())->assertOk();
    }

    $this->postJson(route('pay.authorizenet.charge', anetPayment()), chargeBody())->assertStatus(429);
});

it('never logs the data value', function () {
    $payment = anetPayment();
    fakeAnet([], [], 'timeout');
    Queue::fake();
    Log::spy();

    $this->postJson(route('pay.authorizenet.charge', $payment), chargeBody())->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message, $context = []) => ! str_contains(json_encode([$message, $context]), 'opaque-code-from-acceptjs')
    );
});

it('removes the Accept Hosted routes', function () {
    $payment = anetPayment();

    $this->postJson("/pay/{$payment->uuid}/authorizenet/token")->assertStatus(404);
    $this->get('/pay/authorizenet/communicator')->assertNotFound();
    expect(file_exists(public_path('authorizenet-communicator.js')))->toBeFalse();
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
    $payment = anetPayment(['authorizenet_first_attempt_at' => null]);
    $old = anetPayment(['authorizenet_first_attempt_at' => now()->subMinutes(61)]);
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

it('tells the pay page when the charge is held so a reload does not show the card form again', function () {
    fakeAnet();
    $held = anetPayment(['authorizenet_transaction_id' => '60000001']);
    $fresh = anetPayment();

    expect($this->get(route('pay.show', $held))->viewData('page')['props']['held'])->toBeTrue()
        ->and($this->get(route('pay.show', $fresh))->viewData('page')['props']['held'])->toBeFalse();
});

it('adds only the Accept.js hosts to the CSP on the Authorize.net pay page', function () {
    fakeAnet();
    $payment = anetPayment();
    $other = Payment::factory()->create(['status' => 'pending']);

    $csp = $this->get(route('pay.show', $payment))->headers->get('Content-Security-Policy');
    expect($csp)->toContain('https://jstest.authorize.net')->toContain('https://apitest.authorize.net')
        ->and($csp)->not->toContain('form-action')
        ->not->toContain('frame-ancestors')
        ->not->toContain('unsafe-eval')
        ->not->toContain('https://test.authorize.net');

    $stripeCsp = $this->get(route('pay.show', $other))->headers->get('Content-Security-Policy');
    expect($stripeCsp)->not->toContain('authorize.net');
});

it('passes only public identifiers to the pay page', function () {
    $payment = anetPayment();
    $payment->authorizenetAccount->update(['public_client_key' => 'pub-key-123']);
    $payment->authorizenetAccount->forceFill(['signature_key' => 'sig-secret'])->save();

    $page = $this->get(route('pay.show', $payment))->assertOk()->viewData('page')['props'];

    expect($page['authorizenetAccount'])->toBe([
        'api_login_id' => $payment->authorizenetAccount->api_login_id,
        'public_client_key' => 'pub-key-123',
        'script_url' => 'https://jstest.authorize.net/v1/Accept.js',
    ])->and(json_encode($page))->not->toContain('sig-secret')->not->toContain('placeholder_transaction_key');
});

it('treats an account with no public client key as unavailable', function () {
    $payment = anetPayment();
    $payment->authorizenetAccount->update(['public_client_key' => null]);

    $this->get(route('pay.show', $payment))->assertNotFound();
});

it('rejects an edit of amount, currency or account once a charge was attempted', function () {
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

    it('finds the payment from a legacy id and uuid invoice number', function () {
        $payment = anetPayment();
        fakeAnet([], ['60000001' => anetTx($payment, ['order' => ['invoiceNumber' => AuthorizenetInvoice::legacy($payment)]])]);

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
        $tooOld = anetPayment(['authorizenet_last_attempt_at' => now()->subDays(2), 'authorizenet_first_attempt_at' => now()->subDays(2)]);

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
