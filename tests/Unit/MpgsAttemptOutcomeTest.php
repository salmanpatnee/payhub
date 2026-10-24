<?php

use App\Services\Mpgs\MpgsAttemptOutcome;

/**
 * Shapes follow real Bank Alfalah retrieve-order bodies (no card data, no IPs).
 */
function mpgsOrderFixture(string $authStatus, array $transactions, array $extra = []): array
{
    return array_merge([
        'status' => 'FAILED',
        'authenticationStatus' => $authStatus,
        'transaction' => $transactions,
    ], $extra);
}

function mpgsAuthTx(string $result, string $code, string $threeDs, ?string $reasonCode = null): array
{
    return [
        'result' => $result,
        'response' => ['gatewayCode' => $code],
        'transaction' => ['type' => 'AUTHENTICATION', 'id' => 'auth-1'],
        'authentication' => ['3ds2' => array_filter(['transactionStatus' => $threeDs, 'statusReasonCode' => $reasonCode])],
    ];
}

function mpgsPayTx(string $result, array $response, array $extra = []): array
{
    return array_merge([
        'result' => $result,
        'response' => $response,
        'transaction' => ['type' => 'PAYMENT', 'id' => 'txn-pay'],
    ], $extra);
}

it('says the bank refused 3DS when authentication failed and no charge was sent', function () {
    $order = mpgsOrderFixture('AUTHENTICATION_FAILED', [mpgsAuthTx('FAILURE', 'DECLINED', 'N', '01')]);

    $outcome = MpgsAttemptOutcome::fromOrder($order, 'txn-pay');

    expect($outcome['reason'])->toBe('Bank refused the 3-D Secure check');
    expect($outcome['auth_status'])->toBe('AUTHENTICATION_FAILED');
    expect($outcome['gateway_code'])->toBe('DECLINED');
});

it('says the bank rejected 3DS when the issuer returned R', function () {
    $order = mpgsOrderFixture('AUTHENTICATION_REJECTED', [mpgsAuthTx('FAILURE', 'DECLINED', 'R', '11')]);

    expect(MpgsAttemptOutcome::fromOrder($order, 'txn-pay')['reason'])->toBe('Bank rejected the 3-D Secure check');
});

it('says the customer did not finish 3DS when the challenge was left pending', function () {
    $order = mpgsOrderFixture('AUTHENTICATION_PENDING', [mpgsAuthTx('PENDING', 'PENDING', 'C')], ['status' => 'AUTHENTICATION_INITIATED']);

    expect(MpgsAttemptOutcome::fromOrder($order, 'txn-pay')['reason'])->toBe('Customer did not finish the 3-D Secure check');
});

it('says the charge never completed when 3DS passed and no payment step exists', function () {
    $order = mpgsOrderFixture('AUTHENTICATION_SUCCESSFUL', [mpgsAuthTx('SUCCESS', 'APPROVED', 'Y')], ['status' => 'AUTHENTICATED']);

    expect(MpgsAttemptOutcome::fromOrder($order, 'txn-pay')['reason'])->toBe('Card check passed but the charge was never completed');
});

it('names the fraud rules that scored 50 or more when the gateway blocked the charge', function () {
    $order = mpgsOrderFixture('AUTHENTICATION_SUCCESSFUL', [
        mpgsAuthTx('SUCCESS', 'APPROVED', 'Y'),
        mpgsPayTx('FAILURE', ['gatewayCode' => 'BLOCKED']),
    ], ['risk' => ['response' => ['gatewayCode' => 'REJECTED', 'totalScore' => 100, 'rule' => [
        ['id' => 'mer_city', 'score' => 6, 'type' => 'EXTERNAL_RULE'],
        ['id' => 'BL009c', 'score' => 100, 'type' => 'EXTERNAL_RULE'],
        ['id' => 'LB472', 'score' => 100, 'type' => 'EXTERNAL_RULE'],
        ['data' => '203.0.113.9', 'type' => 'MERCHANT_RULE'],
    ]]]]);

    $outcome = MpgsAttemptOutcome::fromOrder($order, 'txn-pay');

    expect($outcome['reason'])->toBe('Blocked by gateway fraud rules (BL009c, LB472)');
    expect($outcome['gateway_code'])->toBe('BLOCKED');
    expect($outcome['reason'])->not->toContain('203.0.113.9');
});

it('blames the country and IP rules when the block carries no scored rule', function () {
    $order = mpgsOrderFixture('AUTHENTICATION_SUCCESSFUL', [
        mpgsAuthTx('SUCCESS', 'APPROVED', 'Y'),
        mpgsPayTx('FAILURE', ['gatewayCode' => 'BLOCKED']),
    ], ['risk' => ['response' => ['gatewayCode' => 'REJECTED', 'rule' => [['id' => 'MSO_IP_COUNTRY', 'type' => 'MSO_RULE']]]]]);

    expect(MpgsAttemptOutcome::fromOrder($order, 'txn-pay')['reason'])->toBe('Blocked by gateway country/IP rules');
});

it('combines a refused 3DS with a later risk block', function () {
    $order = mpgsOrderFixture('AUTHENTICATION_FAILED', [
        mpgsAuthTx('FAILURE', 'DECLINED', 'N', '01'),
        mpgsPayTx('FAILURE', ['gatewayCode' => 'BLOCKED']),
    ], ['risk' => ['response' => ['gatewayCode' => 'REJECTED']]]);

    expect(MpgsAttemptOutcome::fromOrder($order, 'txn-pay')['reason'])
        ->toBe('Bank refused the 3-D Secure check, then blocked by gateway country/IP rules');
});

it("reports the card's bank code and message when the issuer declined", function () {
    $order = mpgsOrderFixture('AUTHENTICATION_SUCCESSFUL', [
        mpgsAuthTx('SUCCESS', 'APPROVED', 'Y'),
        mpgsPayTx('FAILURE', ['gatewayCode' => 'DECLINED', 'acquirerCode' => '83', 'acquirerMessage' => 'Fraud/Security (Mastercard use only)']),
    ], ['risk' => ['response' => ['gatewayCode' => 'ACCEPTED', 'totalScore' => 64]]]);

    $outcome = MpgsAttemptOutcome::fromOrder($order, 'txn-pay');

    expect($outcome['reason'])->toBe("Card's bank declined (code 83: Fraud/Security (Mastercard use only))");
    expect($outcome['gateway_code'])->toBe('DECLINED');
});

it('gives no reason for an approved charge or an empty order', function () {
    $approved = mpgsOrderFixture('AUTHENTICATION_SUCCESSFUL', [
        mpgsAuthTx('SUCCESS', 'APPROVED', 'Y'),
        mpgsPayTx('SUCCESS', ['gatewayCode' => 'APPROVED']),
    ], ['status' => 'CAPTURED']);

    expect(MpgsAttemptOutcome::fromOrder($approved, 'txn-pay')['reason'])->toBeNull();
    expect(MpgsAttemptOutcome::fromOrder([], 'txn-pay'))->toBe(['auth_status' => null, 'gateway_code' => null, 'reason' => null]);
});
