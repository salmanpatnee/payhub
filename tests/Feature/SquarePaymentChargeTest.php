<?php

use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Payments\PaymentsClient;
use Square\SquareClient;
use Square\Types\CreatePaymentResponse;
use Square\Types\Payment as SquarePayment;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

/**
 * Mock the per-account SquareClient. The optional $captureRequest closure receives the
 * CreatePaymentRequest so tests can assert server-side values (e.g. amount from the DB).
 */
function mockSquareClient(string $returnedPaymentId = 'sq_payment_mock_123', ?callable $captureRequest = null): void
{
    // Return type is enforced (CreatePaymentResponse) — use real SDK value objects.
    $mockResponse = new CreatePaymentResponse([
        'payment' => new SquarePayment(['id' => $returnedPaymentId, 'status' => 'PENDING']),
    ]);

    $mockPayments = Mockery::mock(PaymentsClient::class);
    $expectation = $mockPayments->shouldReceive('create');
    if ($captureRequest !== null) {
        $expectation->with(Mockery::on(function ($request) use ($captureRequest) {
            $captureRequest($request);

            return true;
        }));
    }
    $expectation->andReturn($mockResponse);

    $mockSquare = Mockery::mock(SquareClient::class);
    $mockSquare->payments = $mockPayments;

    app()->bind(SquareClient::class, fn () => $mockSquare);
}

function chargeSquare(string $uuid, array $body, bool $withConsent = true): TestResponse
{
    if ($withConsent) {
        recordSquareConsent(Payment::where('uuid', $uuid)->firstOrFail());
    }

    return test()->postJson("/pay/{$uuid}/square", $body);
}

function recordSquareConsent(Payment $payment): void
{
    $payment->consents()->create([
        'policy_versions' => ['terms' => '1'],
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'accepted_at' => now(),
    ]);
}

it('guest can charge a square payment without authentication', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);
    mockSquareClient();

    chargeSquare($payment->uuid, ['source_id' => 'cnon:card-nonce-ok'])
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('stores square_payment_id but leaves status pending until the webhook', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending', 'square_payment_id' => null]);
    mockSquareClient('sq_payment_abc');

    chargeSquare($payment->uuid, ['source_id' => 'cnon:card-nonce-ok']);

    $payment->refresh();
    expect($payment->square_payment_id)->toBe('sq_payment_abc');
    expect($payment->status)->toBe('pending');
    expect($payment->paid_at)->toBeNull();
});

it('charges the amount from the database, never from the client', function () {
    $payment = Payment::factory()->square()->create([
        'status' => 'pending',
        'amount' => 4250,
        'currency' => 'gbp',
    ]);

    $capturedAmount = null;
    $capturedCurrency = null;
    mockSquareClient('sq_payment_amt', function ($request) use (&$capturedAmount, &$capturedCurrency): void {
        $capturedAmount = $request->getAmountMoney()->getAmount();
        $capturedCurrency = $request->getAmountMoney()->getCurrency();
    });

    // Client attempts to inject a different amount — must be ignored.
    chargeSquare($payment->uuid, ['source_id' => 'cnon:ok', 'amount' => 1])
        ->assertOk();

    expect($capturedAmount)->toBe(4250);
    expect($capturedCurrency)->toBe('GBP');
});

it('forwards the verification_token for SCA when provided', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);

    $capturedToken = null;
    mockSquareClient('sq_payment_sca', function ($request) use (&$capturedToken): void {
        $capturedToken = $request->getVerificationToken();
    });

    chargeSquare($payment->uuid, ['source_id' => 'cnon:ok', 'verification_token' => 'verf_token_xyz'])
        ->assertOk();

    expect($capturedToken)->toBe('verf_token_xyz');
});

it('rejects a charge against a non-square payment', function () {
    $payment = Payment::factory()->create(['status' => 'pending']); // stripe provider
    // No mock — controller must reject before any SquareClient call.

    chargeSquare($payment->uuid, ['source_id' => 'cnon:ok'])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);
});

it('rejects a charge against a completed square payment', function () {
    $payment = Payment::factory()->square()->create(['status' => 'completed']);

    chargeSquare($payment->uuid, ['source_id' => 'cnon:ok'])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);
});

it('requires a source_id', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);

    chargeSquare($payment->uuid, [])
        ->assertStatus(422);
});

it('rejects a charge when the client has not given consent, without calling Square', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);
    // No mock: a call to Square would fail the test.
    app()->bind(SquareClient::class, fn () => throw new RuntimeException('Square must not be called'));

    chargeSquare($payment->uuid, ['source_id' => 'cnon:ok'], withConsent: false)
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'This payment cannot be processed.']);

    expect($payment->fresh()->square_payment_id)->toBeNull();
});

it('square charge route is CSRF-excluded', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);
    recordSquareConsent($payment);
    mockSquareClient();

    // No CSRF token — must not 419.
    test()->call('POST', "/pay/{$payment->uuid}/square", [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode(['source_id' => 'cnon:ok']))
        ->assertOk();
});

it('does not expose the square access_token on the pay page', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);

    test()->get("/pay/{$payment->uuid}")
        ->assertInertia(fn ($page) => $page
            ->component('ClientPayment/Pay')
            ->where('provider', 'square')
            ->has('squareAccount.application_id')
            ->has('squareAccount.location_id')
            ->missing('squareAccount.access_token')
            ->missing('clientSecret')
        );
});

// ---- Spec 0016: a declined charge is logged from the call (no webhook follows a refused payment) ----

function mockSquareThrowing(Throwable $exception): void
{
    $mockPayments = Mockery::mock(PaymentsClient::class);
    $mockPayments->shouldReceive('create')->andThrow($exception);

    $mockSquare = Mockery::mock(SquareClient::class);
    $mockSquare->payments = $mockPayments;

    app()->bind(SquareClient::class, fn () => $mockSquare);
}

function squareDecline(int $status = 402, string $detail = 'Card declined.'): SquareApiException
{
    return new SquareApiException('Declined', $status, ['errors' => [
        ['category' => 'PAYMENT_METHOD_ERROR', 'code' => 'CARD_DECLINED', 'detail' => $detail],
    ]]);
}

it('logs a declined square charge as a failed call row and keeps the same customer message', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending', 'amount' => 7500, 'currency' => 'usd']);
    mockSquareThrowing(squareDecline());

    chargeSquare($payment->uuid, ['source_id' => 'cnon:card-nonce-ok'])
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'Your payment could not be processed. Please check your card details and try again.']);

    $row = PaymentAttempt::where('payment_id', $payment->id)->sole();
    expect($row->provider)->toBe('square')
        ->and($row->status)->toBe('failed')
        ->and($row->source)->toBe('call')
        ->and($row->event)->toBe('CREATE_PAYMENT_DECLINED')
        ->and($row->error_code)->toBe('CARD_DECLINED')
        ->and($row->reason)->toBe('Card declined.')
        ->and($row->amount)->toBe(7500)
        ->and($row->amount_reported)->toBeFalse()
        ->and($row->response['errors'][0]['category'])->toBe('PAYMENT_METHOD_ERROR')
        ->and($payment->fresh()->status)->toBe('pending');
});

it('gives each declined square attempt its own row in order', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);
    mockSquareThrowing(squareDecline());

    chargeSquare($payment->uuid, ['source_id' => 'cnon:one'])->assertStatus(422);
    chargeSquare($payment->uuid, ['source_id' => 'cnon:two'], withConsent: false)->assertStatus(422);

    expect(PaymentAttempt::where('payment_id', $payment->id)->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2]);
});

it('does not log a square 5xx or a network failure, since the outcome is unclear', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);

    mockSquareThrowing(squareDecline(500));
    chargeSquare($payment->uuid, ['source_id' => 'cnon:a'])->assertStatus(422);

    mockSquareThrowing(new SquareException('network down'));
    chargeSquare($payment->uuid, ['source_id' => 'cnon:b'], withConsent: false)->assertStatus(502);

    expect(PaymentAttempt::count())->toBe(0);
});

it('still returns the same decline message when the attempt log is broken', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);
    mockSquareThrowing(squareDecline());
    Schema::drop('payment_attempts');

    chargeSquare($payment->uuid, ['source_id' => 'cnon:x'])
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'Your payment could not be processed. Please check your card details and try again.']);

    expect($payment->fresh()->status)->toBe('pending');
});

it('masks card like digits in a square decline detail', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);
    mockSquareThrowing(squareDecline(402, 'Card 4111111111111111 was declined'));

    chargeSquare($payment->uuid, ['source_id' => 'cnon:x'])->assertStatus(422);

    $row = PaymentAttempt::where('payment_id', $payment->id)->sole();
    expect($row->reason)->not->toContain('4111111111111111')->and($row->reason)->toContain('[masked]');
});
