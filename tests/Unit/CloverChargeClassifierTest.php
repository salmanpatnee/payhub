<?php

use App\Services\Clover\CloverChargeClassifier;

it('classifies paid and captured with a matching amount/currency as approved', function () {
    $result = CloverChargeClassifier::classify(200, [
        'paid' => true,
        'captured' => true,
        'amount' => 1000,
        'currency' => 'usd',
    ], 1000, 'usd');

    expect($result)->toBe('approved');
});

it('classifies paid true but captured false as unknown, never approved', function () {
    $result = CloverChargeClassifier::classify(200, [
        'paid' => true,
        'captured' => false,
        'amount' => 1000,
        'currency' => 'usd',
    ], 1000, 'usd');

    expect($result)->toBe('unknown');
});

it('classifies an amount mismatch as unknown, never approved', function () {
    $result = CloverChargeClassifier::classify(200, [
        'paid' => true,
        'captured' => true,
        'amount' => 999,
        'currency' => 'usd',
    ], 1000, 'usd');

    expect($result)->toBe('unknown');
});

it('classifies a card_error response as declined', function () {
    $body = ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'do_not_honor', 'message' => 'Your card was declined.']];

    expect(CloverChargeClassifier::classify(200, $body, 1000, 'usd'))->toBe('declined');
    expect(CloverChargeClassifier::classify(402, $body, 1000, 'usd'))->toBe('declined');
});

it('classifies paid false with a decline_code as declined regardless of http status', function () {
    expect(CloverChargeClassifier::classify(200, ['paid' => false, 'decline_code' => 'card_declined'], 1000, 'usd'))->toBe('declined');
    expect(CloverChargeClassifier::classify(402, ['paid' => false, 'decline_code' => 'card_declined'], 1000, 'usd'))->toBe('declined');
});

it('classifies a decline delivered as a 4xx as declined, not hard_error', function () {
    $result = CloverChargeClassifier::classify(400, ['error' => ['type' => 'card_error', 'code' => 'do_not_honor']], 1000, 'usd');

    expect($result)->toBe('declined');
});

// Regression (2026-09-18): live verification of AC-9 hit "Your card was declined"
// on every submit, even with Clover's own valid sandbox test card. The real cause
// was PayHub sending a 36-char UUID as external_reference_id, which Clover caps
// at 12 characters and rejects with {"error":{"type":"invalid_request_error",...}}
// — a request-shape problem PayHub caused, not a card decline. The classifier had
// treated ANY body with an "error" key as a decline, hiding the real error behind
// a misleading customer-facing message. Fixed in ClientPaymentController (shorter
// generated key) and here (only card_error is a decline; invalid_request_error is
// a hard_error).
it('classifies an invalid_request_error as hard_error, never as a decline', function () {
    $body = ['message' => '400 Bad Request', 'error' => [
        'type' => 'invalid_request_error',
        'code' => 'invalid_request',
        'message' => 'Please provide valid format or check allowable max length of 12.',
    ]];

    expect(CloverChargeClassifier::classify(400, $body, 1000, 'usd'))->toBe('hard_error');
});

it('classifies an authentication_error or api_error as hard_error, never as a decline', function () {
    expect(CloverChargeClassifier::classify(401, ['error' => ['type' => 'authentication_error']], 1000, 'usd'))->toBe('hard_error');
    expect(CloverChargeClassifier::classify(500, ['error' => ['type' => 'api_error']], 1000, 'usd'))->toBe('hard_error');
});

it('classifies an unauthorized/malformed request with no decline signal as hard_error', function () {
    expect(CloverChargeClassifier::classify(401, ['message' => 'invalid credentials'], 1000, 'usd'))->toBe('hard_error');
    expect(CloverChargeClassifier::classify(400, ['message' => 'malformed request'], 1000, 'usd'))->toBe('hard_error');
    expect(CloverChargeClassifier::classify(404, ['message' => 'unknown merchant'], 1000, 'usd'))->toBe('hard_error');
});

it('classifies an empty or unrecognized body as unknown', function () {
    expect(CloverChargeClassifier::classify(200, [], 1000, 'usd'))->toBe('unknown');
    expect(CloverChargeClassifier::classify(500, [], 1000, 'usd'))->toBe('unknown');
});

it('classifies a rate_limit_error or api_connection_error as unknown, not hard_error, so the resolver job retries', function () {
    expect(CloverChargeClassifier::classify(429, ['error' => ['type' => 'rate_limit_error']], 1000, 'usd'))->toBe('unknown');
    expect(CloverChargeClassifier::classify(500, ['error' => ['type' => 'api_connection_error']], 1000, 'usd'))->toBe('unknown');
});

// Regression (2026-09-18): live verification of AC-8/AC-9 got stuck on
// "Confirming your payment…" forever after submitting one of Clover's own
// documented decline test cards — no error ever showed. Captured Clover's real
// decline response directly against the sandbox: it carries NEITHER a top-level
// "paid" field NOR an "error.type" field, and its decline code is camelCase
// (declineCode), not the snake_case decline_code Clover's field-reference docs
// use. The classifier required paid===false or a snake_case decline_code, so
// this genuine decline matched neither the decline nor the hard_error branch
// and fell into "unknown" — leaving the payment (and the client) stuck pending
// forever, since there was nothing for the resolver job to ever resolve either.
it('classifies clover\'s real (undocumented-shape) decline response as declined, not unknown', function () {
    $body = [
        'message' => '402 Payment Required',
        'error' => [
            'code' => 'card_declined',
            'message' => 'DECLINED: No reason provided.',
            'charge' => '0DZ07JZP5XSVT',
            'declineCode' => 'issuer_declined',
        ],
    ];

    expect(CloverChargeClassifier::classify(402, $body, 100000, 'usd'))->toBe('declined');
});
