<?php

use Illuminate\Testing\TestResponse;

uses(Tests\TestCase::class)->in('Feature', 'Unit');

function fakeStripeSignature(string $payload, string $secret, int $timestamp = 0): string
{
    $timestamp = $timestamp ?: time();
    $signedPayload = $timestamp.'.'.$payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}

/**
 * Send a raw POST request with a Stripe-Signature header.
 * Uses call() with HTTP_STRIPE_SIGNATURE in the server vars so the
 * raw body is preserved exactly for Webhook::constructEvent().
 */
function stripePost(string $url, string $payload, ?string $sig = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sig !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $sig;
    }

    return test()->call('POST', $url, [], [], [], $server, $payload);
}
