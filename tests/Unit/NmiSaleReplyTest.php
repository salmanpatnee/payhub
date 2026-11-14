<?php

use App\Services\Nmi\NmiSaleReply;

function nmiReply(int $status, array $body): NmiSaleReply
{
    return NmiSaleReply::fromResult(['status' => $status, 'body' => $body]);
}

it('maps response codes to outcomes', function (array $body, string $outcome) {
    expect(nmiReply(200, $body)->outcome)->toBe($outcome);
})->with([
    'approved' => [['response_code' => '100', 'id' => 'tx1'], NmiSaleReply::APPROVED],
    'bank decline' => [['response_code' => '200', 'id' => 'tx1'], NmiSaleReply::DECLINED],
    'insufficient funds' => [['response_code' => '202'], NmiSaleReply::DECLINED],
    'expired card' => [['response_code' => '223'], NmiSaleReply::CHECK_DETAILS],
    'bad cvv' => [['response_code' => '225'], NmiSaleReply::CHECK_DETAILS],
    'processor comms error' => [['response_code' => '420'], NmiSaleReply::UNCLEAR],
    'duplicate at processor' => [['response_code' => '430'], NmiSaleReply::UNCLEAR],
    'gateway rejected' => [['response_code' => '300'], NmiSaleReply::ERROR],
    'no response code' => [['id' => 'tx1'], NmiSaleReply::UNCLEAR],
]);

it('never treats approved without a transaction id as approved', function () {
    $reply = nmiReply(200, ['response_code' => '100']);

    expect($reply->outcome)->toBe(NmiSaleReply::UNCLEAR)->and($reply->transactionId)->toBeNull();
});

it('carries the transaction id on an approved reply', function () {
    expect(nmiReply(200, ['response_code' => '100', 'id' => 'tx9'])->transactionId)->toBe('tx9');
});

it('treats a server error or empty body as unclear', function () {
    expect(nmiReply(502, ['response_code' => '100', 'id' => 'tx1'])->outcome)->toBe(NmiSaleReply::UNCLEAR)
        ->and(nmiReply(200, [])->outcome)->toBe(NmiSaleReply::UNCLEAR);
});

it('treats a rejected request as error, and a duplicate as unclear', function () {
    $error = nmiReply(400, ['error_code' => 'E_INVALID_SUBMISSION', 'message' => 'bad field']);
    $duplicate = nmiReply(400, ['error_code' => 'E_DUPLICATE']);

    expect($error->outcome)->toBe(NmiSaleReply::ERROR)
        ->and($error->reason())->toBe('NMI rejected the request fields')
        ->and($duplicate->outcome)->toBe(NmiSaleReply::UNCLEAR)
        ->and($duplicate->reason())->toBe('Duplicate sale blocked by NMI');
});

it('builds a readable reason for unknown error codes', function () {
    expect(nmiReply(400, ['error_code' => 'E_X', 'message' => 'oops'])->reason())->toBe('NMI error E_X: oops')
        ->and(nmiReply(400, ['error_code' => 'E_X'])->reason())->toBe('NMI error E_X');
});

it('gives plain operator reasons for known codes', function () {
    expect(nmiReply(200, ['response_code' => '202'])->reason())->toBe('Insufficient funds')
        ->and(nmiReply(200, ['response_code' => '100', 'id' => 'tx1'])->reason())->toBeNull()
        ->and(NmiSaleReply::unclear()->reason())->toBe('No clear answer from NMI');
});

it('keeps only short AVS and CVV letters', function () {
    $reply = nmiReply(200, ['response_code' => '200', 'avs_response' => 'N', 'cvv_response' => str_repeat('M', 20)]);

    expect($reply->detail['avs'])->toBe('N')->and($reply->detail['cvv'])->toBe('MMMMMMMM');
});

it('shows only fixed messages to the client, falling back to the error message', function () {
    expect(NmiSaleReply::message(NmiSaleReply::APPROVED))->toBe('Payment received.')
        ->and(NmiSaleReply::message('whatever'))->toBe(NmiSaleReply::message(NmiSaleReply::ERROR));
});

it('keeps the transaction id and detail when the outcome changes', function () {
    $reply = nmiReply(200, ['response_code' => '100', 'id' => 'tx1'])->asUnclear();

    expect($reply->outcome)->toBe(NmiSaleReply::UNCLEAR)
        ->and($reply->transactionId)->toBe('tx1')
        ->and($reply->detail['response_code'])->toBe('100');
});
