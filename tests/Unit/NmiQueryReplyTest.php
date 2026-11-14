<?php

use App\Services\Nmi\NmiQueryReply;

function nmiQueryXml(string $inner): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><nm_response>'.$inner.'</nm_response>';
}

function nmiQueryTransaction(string $actions, string $extra = ''): string
{
    return '<transaction><transaction_id> 123 </transaction_id><condition>Complete</condition>'
        .'<order_id>ord-1</order_id><currency>usd</currency>'.$extra.$actions.'</transaction>';
}

it('reads a successful sale transaction', function () {
    $reply = NmiQueryReply::fromXml(nmiQueryXml(nmiQueryTransaction(
        '<action><action_type>sale</action_type><amount>12.50</amount><success>1</success></action>',
        '<avs_response>Y</avs_response><csc_response>M</csc_response>',
    )));

    expect($reply->ok)->toBeTrue()
        ->and($reply->transactions)->toHaveCount(1)
        ->and($reply->transactions[0])->toMatchArray([
            'transaction_id' => '123',
            'condition' => 'complete',
            'order_id' => 'ord-1',
            'currency' => 'USD',
            'amount_cents' => 1250,
            'sale' => true,
            'sale_success' => true,
            'avs' => 'Y',
            'cvv' => 'M',
        ]);
});

it('treats an empty reply as a valid no match', function () {
    $reply = NmiQueryReply::fromXml(nmiQueryXml(''));

    expect($reply->ok)->toBeTrue()->and($reply->transactions)->toBe([]);
});

it('takes the amount from the sale action, not another action', function () {
    $reply = NmiQueryReply::fromXml(nmiQueryXml(nmiQueryTransaction(
        '<action><action_type>auth</action_type><amount>1.00</amount><success>1</success></action>'
        .'<action><action_type>sale</action_type><amount>9.99</amount><success>0</success></action>',
    )));

    expect($reply->transactions[0]['amount_cents'])->toBe(999)
        ->and($reply->transactions[0]['sale_success'])->toBeFalse();
});

it('marks a transaction with no sale action as not a sale', function () {
    $reply = NmiQueryReply::fromXml(nmiQueryXml(nmiQueryTransaction(
        '<action><action_type>refund</action_type><amount>5.00</amount><success>1</success></action>',
    )));

    expect($reply->transactions[0]['sale'])->toBeFalse()
        ->and($reply->transactions[0]['amount_cents'])->toBeNull();
});

it('returns every transaction in the reply', function () {
    $sale = '<action><action_type>sale</action_type><amount>1.00</amount><success>1</success></action>';
    $reply = NmiQueryReply::fromXml(nmiQueryXml(nmiQueryTransaction($sale).nmiQueryTransaction($sale)));

    expect($reply->transactions)->toHaveCount(2);
});

it('fails the lookup on an error response and strips the reference id', function () {
    $reply = NmiQueryReply::fromXml(nmiQueryXml('<error_response>Specified API key not found REFID:3210</error_response>'));

    expect($reply->ok)->toBeFalse()->and($reply->error)->toBe('Specified API key not found');
});

it('fails on unreadable or wrong root XML', function (string $xml) {
    $reply = NmiQueryReply::fromXml($xml);

    expect($reply->ok)->toBeFalse()->and($reply->error)->toBe('Unreadable reply');
})->with([
    'not xml' => ['not xml at all'],
    'empty' => [''],
    'wrong root' => ['<html><body>Bad gateway</body></html>'],
]);

it('does not load external entities', function () {
    $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><nm_response><error_response>&e;</error_response></nm_response>';

    expect(NmiQueryReply::fromXml($xml)->error)->not->toContain('root:');
});

it('builds a failed reply', function () {
    $reply = NmiQueryReply::failed('timeout');

    expect($reply->ok)->toBeFalse()->and($reply->error)->toBe('timeout')->and($reply->transactions)->toBe([]);
});
