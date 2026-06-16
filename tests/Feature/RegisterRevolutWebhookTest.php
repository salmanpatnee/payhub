<?php

use App\Models\RevolutAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The command registers a webhook via the Merchant API and persists the returned
// signing secret on the account so webhook signature verification can pass.
it('registers a webhook and stores the signing secret', function () {
    Http::fake([
        '*/api/webhooks' => Http::sequence()
            ->push([], 200) // list: no existing webhooks
            ->push([
                'id' => 'wh_123',
                'url' => 'https://example.test/webhook/revolut/1',
                'events' => ['ORDER_COMPLETED', 'ORDER_PAYMENT_FAILED'],
                'signing_secret' => 'wsk_test_secret',
            ], 200),
    ]);

    $account = RevolutAccount::factory()->create(['webhook_secret' => null]);

    $this->artisan('revolut:register-webhook', [
        'account' => $account->id,
        '--url' => "https://example.test/webhook/revolut/{$account->id}",
    ])->assertSuccessful();

    expect($account->refresh()->webhook_secret)->toBe('wsk_test_secret');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/webhooks')
        && $request['url'] === "https://example.test/webhook/revolut/{$account->id}"
        && in_array('ORDER_COMPLETED', $request['events'], true));
});

// A stale webhook pointing at the same URL is deleted before re-creating, so the
// fresh signing secret is captured rather than leaving a duplicate behind.
it('clears an existing webhook for the same url before re-registering', function () {
    $url = 'https://example.test/webhook/revolut/1';

    Http::fake([
        '*/api/webhooks/wh_old' => Http::response([], 204),
        '*/api/webhooks' => Http::sequence()
            ->push([['id' => 'wh_old', 'url' => $url, 'events' => ['ORDER_COMPLETED']]], 200)
            ->push(['id' => 'wh_new', 'url' => $url, 'signing_secret' => 'wsk_new'], 200),
    ]);

    $account = RevolutAccount::factory()->create(['id' => 1, 'webhook_secret' => 'wsk_old']);

    $this->artisan('revolut:register-webhook', ['account' => 1, '--url' => $url])
        ->assertSuccessful();

    expect($account->refresh()->webhook_secret)->toBe('wsk_new');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/webhooks/wh_old'));
});

// Plain HTTP is rejected — Revolut only delivers to publicly reachable HTTPS URLs.
it('rejects a non-https webhook url', function () {
    $account = RevolutAccount::factory()->create();

    $this->artisan('revolut:register-webhook', [
        'account' => $account->id,
        '--url' => 'http://localhost/webhook/revolut/1',
    ])->assertFailed();
});
