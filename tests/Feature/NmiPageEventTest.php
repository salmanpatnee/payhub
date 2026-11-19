<?php

use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Services\CardLock\CardLockGuard;
use App\Support\ClientInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake([SendPaymentNotification::class]);
});

function pageEventPayment(array $attributes = []): Payment
{
    return Payment::factory()->nmi()->create(array_merge(['amount' => 1234, 'currency' => 'usd', 'status' => 'pending'], $attributes));
}

function sendPageEvent(Payment $payment, array $data, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'): TestResponse
{
    return test()->withHeaders(['User-Agent' => $userAgent])->postJson("/pay/{$payment->uuid}/nmi/event", $data);
}

it('writes one page issue row with a plain reason and the browser for a fields not loaded event', function () {
    $payment = pageEventPayment();

    sendPageEvent($payment, ['event' => 'fields_not_loaded'])->assertNoContent();

    $row = $payment->nmiChargeAttempts()->sole();
    expect($row->status)->toBe('page_issue')
        ->and($row->reason)->toBe('Card fields did not load on the pay page')
        ->and($row->client_info)->toBe('Chrome on Windows, desktop')
        ->and($row->transaction_id)->toBeNull();
});

it('keeps NMI code and message on a 3DS failure and marks it as a 3DS failure', function () {
    $payment = pageEventPayment();

    sendPageEvent($payment, ['event' => 'three_ds_failed', 'code' => 'TRANSACTION_STATUS_N', 'message' => 'Not Authenticated/Account Not Verified; Transaction denied'])->assertNoContent();

    $row = $payment->nmiChargeAttempts()->sole();
    expect($row->status)->toBe('3ds_failed')
        ->and($row->reason)->toBe('Customer failed or cancelled 3D Secure: [TRANSACTION_STATUS_N] Not Authenticated/Account Not Verified; Transaction denied');
});

it('labels a silent 3DS and a mount failure on their own', function (string $event, string $label, string $status) {
    $payment = pageEventPayment();

    sendPageEvent($payment, ['event' => $event])->assertNoContent();

    $row = $payment->nmiChargeAttempts()->sole();
    expect($row->reason)->toBe($label)->and($row->status)->toBe($status);
})->with([
    ['three_ds_timeout', '3D Secure gave no answer in 60 seconds', '3ds_failed'],
    ['mount_failed', 'The card form could not start on the pay page', 'page_issue'],
    ['three_ds_error', '3D Secure reported an error', '3ds_failed'],
]);

it('keeps the Gateway.js error code and a cleaned message on a 3DS error', function () {
    $payment = pageEventPayment();

    sendPageEvent($payment, ['event' => 'three_ds_error', 'code' => 'GATEWAY_NOT_LOADED', 'message' => 'Payer auth not enabled for 4111111111111111'])->assertNoContent();

    $row = $payment->nmiChargeAttempts()->sole();
    expect($row->status)->toBe('3ds_failed')
        ->and($row->reason)->toBe('3D Secure reported an error: [GATEWAY_NOT_LOADED] Payer auth not enabled for [number removed]');
});

it('rejects an unknown event, a bad code and leaves no row', function () {
    $payment = pageEventPayment();

    sendPageEvent($payment, ['event' => 'drop_table'])->assertStatus(422);
    sendPageEvent($payment, ['event' => 'mount_failed', 'code' => 'bad code <script>'])->assertStatus(422);

    expect($payment->nmiChargeAttempts()->count())->toBe(0);
});

it('writes nothing for a completed payment or another provider', function () {
    $completed = pageEventPayment(['status' => 'completed']);
    $stripe = Payment::factory()->create(['status' => 'pending']);

    sendPageEvent($completed, ['event' => 'fields_not_loaded'])->assertNoContent();
    sendPageEvent($stripe, ['event' => 'fields_not_loaded'])->assertNoContent();

    expect($completed->nmiChargeAttempts()->count())->toBe(0);
});

it('cleans the message: control characters, long digit runs and length', function () {
    $payment = pageEventPayment();

    sendPageEvent($payment, ['event' => 'mount_failed', 'message' => "bad\x07 text 4111111111111111 ".str_repeat('x', 400)])->assertNoContent();

    $reason = $payment->nmiChargeAttempts()->sole()->reason;
    expect($reason)->not->toContain('4111111111111111')
        ->and($reason)->toContain('[number removed]')
        ->and($reason)->not->toContain("\x07")
        ->and(strlen($reason))->toBeLessThanOrEqual(250);
});

it('writes the same event once within a minute', function () {
    $payment = pageEventPayment();

    sendPageEvent($payment, ['event' => 'fields_not_loaded'])->assertNoContent();
    sendPageEvent($payment, ['event' => 'fields_not_loaded'])->assertNoContent();
    sendPageEvent($payment, ['event' => 'mount_failed'])->assertNoContent();

    expect($payment->nmiChargeAttempts()->count())->toBe(2);
});

it('answers 204 and stops writing after 20 events an hour for one payment', function () {
    $payment = pageEventPayment();

    foreach (range(1, 22) as $i) {
        // Vary the text so only the hourly limiter (not the one minute dedupe) is what stops it.
        Cache::forget("nmi:event:{$payment->id}:mount_failed");
        sendPageEvent($payment, ['event' => 'mount_failed', 'message' => "try {$i}"])->assertNoContent();
    }

    expect($payment->nmiChargeAttempts()->count())->toBe(20);
});

it('never locks the card, sets a pause or blocks the next charge', function () {
    Http::fake();
    $payment = pageEventPayment();

    foreach (['fields_not_loaded', 'mount_failed', 'three_ds_failed', 'three_ds_timeout', 'three_ds_error'] as $event) {
        sendPageEvent($payment, ['event' => $event])->assertNoContent();
    }

    expect($payment->nmiChargeAttempts()->count())->toBe(5)
        ->and(app(CardLockGuard::class)->isLocked($payment->fresh()))->toBeFalse()
        ->and(Cache::has("nmi:pause:{$payment->id}"))->toBeFalse()
        ->and($payment->fresh()->status)->toBe('pending');
});

it('stores the browser on the existing 3DS failure report and on sale rows', function () {
    $payment = pageEventPayment();
    $payment->consents()->create(['policy_versions' => [], 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'accepted_at' => now()]);

    test()->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'])
        ->postJson("/pay/{$payment->uuid}/nmi/charge", ['three_ds_failed' => true, 'method' => 'card'])
        ->assertOk()->assertJson(['outcome' => '3ds_failed']);

    expect($payment->nmiChargeAttempts()->sole()->client_info)->toBe('Safari on iOS, mobile');
});

it('describes a browser in a few words', function (?string $ua, string $expected) {
    expect(ClientInfo::describe($ua))->toBe($expected);
})->with([
    'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome on Windows, desktop'],
    'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', 'Safari on iOS, mobile'],
    'firefox android' => ['Mozilla/5.0 (Android 14; Mobile; rv:130.0) Gecko/130.0 Firefox/130.0', 'Firefox on Android, mobile'],
    'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'Edge on Windows, desktop'],
    'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', 'Safari on iOS, tablet'],
    'empty' => [null, 'Unknown browser'],
]);
