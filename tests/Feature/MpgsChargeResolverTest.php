<?php

use App\Jobs\SendPaymentNotification;
use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use App\Services\Mpgs\MpgsChargeResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function mpgsPendingAttempt(string $paymentStatus = 'pending'): MpgsChargeAttempt
{
    $payment = Payment::factory()->mpgs()->create(['status' => $paymentStatus, 'amount' => 1050, 'currency' => 'usd']);

    return MpgsChargeAttempt::factory()->pending()->create(['payment_id' => $payment->id]);
}

it('approving completes the payment once, sets the order id and clears the session', function () {
    Bus::fake();
    $attempt = mpgsPendingAttempt();

    (new MpgsChargeResolver)->resolve($attempt, 'approved', [
        'response' => ['gatewayCode' => 'APPROVED'],
        'transaction' => ['receipt' => '654321'],
    ]);

    $payment = $attempt->payment->fresh();
    expect($payment->status)->toBe('completed');
    expect($payment->paid_at)->not->toBeNull();
    expect($payment->mpgs_order_id)->toBe($attempt->order_id);

    $attempt->refresh();
    expect($attempt->status)->toBe('approved');
    expect($attempt->session_id)->toBeNull();
    expect($attempt->receipt)->toBe('654321');
    expect($attempt->gateway_code)->toBe('APPROVED');

    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('a second approval of the same payment does not notify twice', function () {
    Bus::fake();
    $attempt = mpgsPendingAttempt();
    $resolver = new MpgsChargeResolver;

    $resolver->resolve($attempt, 'approved', []);
    $resolver->resolve($attempt->fresh(), 'approved', []);

    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('a failed payment can still be completed by a later approved attempt', function () {
    Bus::fake();
    $attempt = mpgsPendingAttempt('failed');

    (new MpgsChargeResolver)->resolve($attempt, 'approved', []);

    expect($attempt->payment->fresh()->status)->toBe('completed');
    Bus::assertDispatchedTimes(SendPaymentNotification::class, 1);
});

it('declining fails a pending payment and keeps the raw reason for operators', function () {
    $attempt = mpgsPendingAttempt();

    (new MpgsChargeResolver)->resolve($attempt, 'declined', [
        'response' => ['gatewayCode' => 'DECLINED', 'acquirerMessage' => 'Do not honour'],
    ]);

    expect($attempt->payment->fresh()->status)->toBe('failed');
    $attempt->refresh();
    expect($attempt->status)->toBe('declined');
    expect($attempt->decline_reason)->toBe('Do not honour');
    expect($attempt->session_id)->toBeNull();
});

it('saves the bank code and 3DS result on a charge the bank declined', function () {
    $attempt = mpgsPendingAttempt();

    (new MpgsChargeResolver)->resolve($attempt, 'declined', [
        'result' => 'FAILURE',
        'response' => ['gatewayCode' => 'DECLINED', 'acquirerCode' => '83', 'acquirerMessage' => 'Fraud/Security (Mastercard use only)'],
        'order' => ['authenticationStatus' => 'AUTHENTICATION_SUCCESSFUL'],
    ]);

    $attempt->refresh();
    expect($attempt->auth_status)->toBe('AUTHENTICATION_SUCCESSFUL');
    expect($attempt->gateway_code)->toBe('DECLINED');
    expect($attempt->decline_reason)->toBe("Card's bank declined (code 83: Fraud/Security (Mastercard use only))");
});

it('records a missing card security code as the reason for a hard error', function () {
    $attempt = mpgsPendingAttempt();

    (new MpgsChargeResolver)->resolve($attempt, 'hard_error', [
        'result' => 'ERROR',
        'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'Value missing', 'field' => 'sourceOfFunds.provided.card.securityCode'],
    ]);

    expect($attempt->fresh()->decline_reason)->toBe('Value missing (sourceOfFunds.provided.card.securityCode)');
});

it('a decline never overwrites a completed payment', function () {
    $attempt = mpgsPendingAttempt('completed');

    (new MpgsChargeResolver)->resolve($attempt, 'declined', ['response' => ['gatewayCode' => 'DECLINED']]);

    expect($attempt->payment->fresh()->status)->toBe('completed');
});

it('a hard error leaves the payment untouched and logs without secrets', function () {
    Log::spy();
    $attempt = mpgsPendingAttempt();

    (new MpgsChargeResolver)->resolve($attempt, 'hard_error', [
        'result' => 'ERROR',
        'error' => ['cause' => 'INVALID_REQUEST', 'explanation' => 'bad currency'],
        'session' => ['id' => 'SESSION-SECRET'],
    ]);

    expect($attempt->payment->fresh()->status)->toBe('pending');
    expect($attempt->fresh()->status)->toBe('hard_error');
    expect($attempt->fresh()->session_id)->toBeNull();
    expect($attempt->fresh()->decline_reason)->toBe('bad currency');

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context) => ! str_contains(json_encode($context), 'SESSION-SECRET')
    )->once();
});

it('unknown keeps the attempt pending, counts the check and leaves the payment alone', function () {
    $attempt = mpgsPendingAttempt();

    (new MpgsChargeResolver)->resolve($attempt, 'unknown', []);

    $attempt->refresh();
    expect($attempt->status)->toBe('pending');
    expect($attempt->attempts_count)->toBe(1);
    expect($attempt->session_id)->not->toBeNull();
    expect($attempt->payment->fresh()->status)->toBe('pending');
});

it('stores the session id encrypted and enforces unique order ids', function () {
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['session_id' => 'SESSION-PLAIN-VALUE']);

    expect(DB::table('mpgs_charge_attempts')->value('session_id'))->not->toBe('SESSION-PLAIN-VALUE');
    expect($attempt->fresh()->session_id)->toBe('SESSION-PLAIN-VALUE');

    expect(fn () => MpgsChargeAttempt::factory()->create([
        'payment_id' => $attempt->payment_id,
        'sequence' => 2,
        'order_id' => $attempt->order_id,
    ]))->toThrow(QueryException::class);
});

it('enforces one sequence number per payment', function () {
    $attempt = MpgsChargeAttempt::factory()->create();

    expect(fn () => MpgsChargeAttempt::factory()->create([
        'payment_id' => $attempt->payment_id,
        'sequence' => $attempt->sequence,
        'order_id' => 'different-order-id',
    ]))->toThrow(QueryException::class);
});
