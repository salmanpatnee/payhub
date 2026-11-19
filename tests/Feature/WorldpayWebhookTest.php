<?php

use App\Jobs\ResolveWorldpayPayment;
use App\Models\Payment;
use App\Models\WorldpayAccount;
use App\Models\WorldpayPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('worldpay-webhook-ip:127.0.0.1');
});

function wpHookPayment(bool $withSecret = true): array
{
    $account = WorldpayAccount::factory()->create();

    if ($withSecret) {
        $account->webhook_key_id = 'key1';
        $account->webhook_secret = 'whsec-test';
        $account->save();
    }

    $payment = Payment::factory()->worldpay()->create(['worldpay_account_id' => $account->id, 'status' => 'pending']);
    $page = WorldpayPage::create(['payment_id' => $payment->id, 'sequence' => 1, 'transaction_reference' => $payment->uuid.'-1', 'url' => 'https://hpp-sandbox.worldpay.com/a', 'expires_at' => now()->addDay()]);

    return [$account, $payment, $page];
}

function wpSend($test, WorldpayAccount $account, string $reference, ?string $signature = null, string $hash = 'sha256', string $keyId = 'key1')
{
    $body = json_encode(['eventId' => 'e1', 'eventDetails' => ['transactionReference' => $reference, 'type' => 'sentForSettlement']]);
    $signature ??= hash_hmac($hash, $body, 'whsec-test');

    return $test->call('POST', "/webhook/worldpay/{$account->id}", [], [], [], [
        'HTTP_EVENT_SIGNATURE' => "{$keyId}/{$hash}/{$signature}", 'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('queues one lookup after the response for a good signature and never changes status itself', function () {
    Bus::fake([ResolveWorldpayPayment::class]);
    [$account, $payment, $page] = wpHookPayment();

    wpSend($this, $account, $page->transaction_reference)->assertOk()->assertSee('ok');

    Bus::assertDispatched(ResolveWorldpayPayment::class, fn ($job) => $job->paymentId === $payment->id && $job->source === 'webhook');
    expect($payment->fresh()->status)->toBe('pending');
});

it('accepts sha512 and the base64 form of the signature', function (string $hash, bool $base64) {
    Bus::fake([ResolveWorldpayPayment::class]);
    [$account, , $page] = wpHookPayment();
    $body = json_encode(['eventDetails' => ['transactionReference' => $page->transaction_reference]]);
    $raw = hash_hmac($hash, $body, 'whsec-test', true);
    $signature = $base64 ? base64_encode($raw) : bin2hex($raw);

    $this->call('POST', "/webhook/worldpay/{$account->id}", [], [], [], ['HTTP_EVENT_SIGNATURE' => "key1/{$hash}/{$signature}", 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    Bus::assertDispatchedTimes(ResolveWorldpayPayment::class, 1);
})->with([['sha256', false], ['sha512', false], ['sha256', true]]);

it('ignores the request, answers 200 and logs when there is no secret, a wrong key id, hash or signature', function (callable $case) {
    Bus::fake([ResolveWorldpayPayment::class]);
    [$account, $payment, $page] = wpHookPayment();

    $response = $case($this, $account, $page);

    $response->assertOk();
    Bus::assertNotDispatched(ResolveWorldpayPayment::class);
    expect($payment->fresh()->status)->toBe('pending');
})->with([
    'wrong signature' => [fn ($t, $a, $p) => wpSend($t, $a, $p->transaction_reference, signature: str_repeat('a', 64))],
    'wrong key id' => [fn ($t, $a, $p) => wpSend($t, $a, $p->transaction_reference, keyId: 'other')],
    'unlisted hash' => [fn ($t, $a, $p) => wpSend($t, $a, $p->transaction_reference, hash: 'md5')],
    'no header' => [fn ($t, $a, $p) => $t->postJson("/webhook/worldpay/{$a->id}", ['eventDetails' => ['transactionReference' => $p->transaction_reference]])],
]);

it('ignores a webhook for an account with no secret saved', function () {
    Bus::fake([ResolveWorldpayPayment::class]);
    [$account, , $page] = wpHookPayment(withSecret: false);

    wpSend($this, $account, $page->transaction_reference)->assertOk();

    Bus::assertNotDispatched(ResolveWorldpayPayment::class);
});

it('does not log the signature header value', function () {
    Log::spy();
    [$account, , $page] = wpHookPayment();

    wpSend($this, $account, $page->transaction_reference, signature: 'deadbeefSECRETSIG');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode([$message, $context]), 'deadbeefSECRETSIG'))->atLeast()->once();
});

it('does nothing for a page that is not found or belongs to another account', function () {
    Bus::fake([ResolveWorldpayPayment::class]);
    [$account] = wpHookPayment();
    [, , $otherPage] = wpHookPayment();

    wpSend($this, $account, 'no-such-reference')->assertOk();
    wpSend($this, $account, $otherPage->transaction_reference)->assertOk();

    Bus::assertNotDispatched(ResolveWorldpayPayment::class);
});

it('runs one lookup per payment every ten seconds and still answers 200 over the limits', function () {
    Bus::fake([ResolveWorldpayPayment::class]);
    [$account, , $page] = wpHookPayment();

    wpSend($this, $account, $page->transaction_reference)->assertOk();
    wpSend($this, $account, $page->transaction_reference)->assertOk();
    Bus::assertDispatchedTimes(ResolveWorldpayPayment::class, 1);

    foreach (range(1, 70) as $i) {
        wpSend($this, $account, $page->transaction_reference)->assertOk();
    }
    Bus::assertDispatchedTimes(ResolveWorldpayPayment::class, 1);
});

it('answers 200 for an unknown or non numeric account', function () {
    $this->postJson('/webhook/worldpay/999')->assertOk();
    $this->postJson('/webhook/worldpay/abc')->assertOk();
});
