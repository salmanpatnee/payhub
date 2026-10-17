<?php

use App\Services\Mpgs\MpgsChargeClassifier;

function mpgsApprovedBody(array $overrides = []): array
{
    return array_replace_recursive([
        'result' => 'SUCCESS',
        'response' => ['gatewayCode' => 'APPROVED'],
        'transaction' => ['id' => 'txn-1', 'type' => 'PAYMENT', 'receipt' => '123456'],
        'order' => ['status' => 'CAPTURED', 'totalCapturedAmount' => '10.50', 'currency' => 'USD'],
    ], $overrides);
}

it('classifies success, approved, captured with matching amount and currency as approved', function () {
    expect(MpgsChargeClassifier::classify(200, mpgsApprovedBody(), 1050, 'usd'))->toBe('approved');
});

it('classifies success without a captured order as unknown, never approved', function () {
    $body = mpgsApprovedBody(['order' => ['status' => 'AUTHORIZED']]);

    expect(MpgsChargeClassifier::classify(200, $body, 1050, 'usd'))->toBe('unknown');
});

it('classifies an amount mismatch as unknown, never approved', function () {
    $body = mpgsApprovedBody(['order' => ['totalCapturedAmount' => '10.49']]);

    expect(MpgsChargeClassifier::classify(200, $body, 1050, 'usd'))->toBe('unknown');
});

it('classifies a currency mismatch as unknown, never approved', function () {
    expect(MpgsChargeClassifier::classify(200, mpgsApprovedBody(), 1050, 'gbp'))->toBe('unknown');
});

it('classifies a missing captured amount as unknown', function () {
    $body = mpgsApprovedBody();
    unset($body['order']['totalCapturedAmount']);

    expect(MpgsChargeClassifier::classify(200, $body, 1050, 'usd'))->toBe('unknown');
});

it('classifies a classifiable decline as declined', function (string $code) {
    $body = ['result' => 'FAILURE', 'response' => ['gatewayCode' => $code]];

    expect(MpgsChargeClassifier::classify(200, $body, 1050, 'usd'))->toBe('declined');
})->with(['DECLINED', 'INSUFFICIENT_FUNDS', 'EXPIRED_CARD', 'AUTHENTICATION_FAILED']);

it('classifies a failure with an unrecognised gateway code as unknown', function () {
    $body = ['result' => 'FAILURE', 'response' => ['gatewayCode' => 'SOMETHING_NEW']];

    expect(MpgsChargeClassifier::classify(200, $body, 1050, 'usd'))->toBe('unknown');
});

it('classifies request and credential problems as hard_error', function (string $cause) {
    $body = ['result' => 'ERROR', 'error' => ['cause' => $cause, 'explanation' => 'nope']];

    expect(MpgsChargeClassifier::classify(400, $body, 1050, 'usd'))->toBe('hard_error');
})->with(['INVALID_REQUEST', 'REQUEST_REJECTED', 'UNAUTHORIZED']);

it('classifies server side errors as unknown, not hard_error', function (string $cause) {
    $body = ['result' => 'ERROR', 'error' => ['cause' => $cause]];

    expect(MpgsChargeClassifier::classify(500, $body, 1050, 'usd'))->toBe('unknown');
})->with(['SERVER_BUSY', 'SERVER_FAILED']);

it('classifies a bare 401 or 403 as hard_error', function (int $status) {
    expect(MpgsChargeClassifier::classify($status, [], 1050, 'usd'))->toBe('hard_error');
})->with([401, 403]);

it('classifies an empty or unrecognised body as unknown', function () {
    expect(MpgsChargeClassifier::classify(200, [], 1050, 'usd'))->toBe('unknown');
    expect(MpgsChargeClassifier::classify(504, ['foo' => 'bar'], 1050, 'usd'))->toBe('unknown');
});

it('finds the PAY transaction by id, not the last one', function () {
    $order = [
        'status' => 'CAPTURED',
        'totalCapturedAmount' => 10.5,
        'currency' => 'USD',
        'transaction' => [
            ['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => 'txn-pay', 'type' => 'PAYMENT']],
            ['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED'], 'transaction' => ['id' => 'txn-other', 'type' => 'PAYMENT']],
        ],
    ];

    expect(MpgsChargeClassifier::fromOrder($order, 'txn-pay', 1050, 'usd'))->toBe('approved');
    expect(MpgsChargeClassifier::fromOrder($order, 'txn-other', 1050, 'usd'))->toBe('declined');
});

it('classifies a retrieved order with no matching transaction as unknown', function () {
    $order = ['status' => 'CAPTURED', 'totalCapturedAmount' => '10.50', 'currency' => 'USD', 'transaction' => []];

    expect(MpgsChargeClassifier::fromOrder($order, 'txn-missing', 1050, 'usd'))->toBe('unknown');
    expect(MpgsChargeClassifier::fromOrder([], 'txn-missing', 1050, 'usd'))->toBe('unknown');
});
