<?php

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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

function chargeSquare(string $uuid, array $body): TestResponse
{
    return test()->postJson("/pay/{$uuid}/square", $body);
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

it('square charge route is CSRF-excluded', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);
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
