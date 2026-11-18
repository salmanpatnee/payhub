<?php

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\WorldpayAccount;
use App\Models\WorldpayLookupAttempt;
use App\Models\WorldpayPage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the Worldpay tables and payment columns', function () {
    expect(Schema::hasTable('worldpay_accounts'))->toBeTrue()
        ->and(Schema::hasTable('worldpay_pages'))->toBeTrue()
        ->and(Schema::hasTable('worldpay_lookup_attempts'))->toBeTrue()
        ->and(Schema::hasColumns('payments', [
            'worldpay_account_id', 'worldpay_payment_id', 'worldpay_charge_started_at', 'worldpay_last_lookup_at',
        ]))->toBeTrue();
});

it('encrypts the account secrets and keeps them out of mass assignment', function () {
    $account = new WorldpayAccount(['account_name' => 'A', 'entity_ref' => 'PO1', 'environment' => 'try',
        'api_username' => 'user-secret', 'api_password' => 'pass-secret', 'webhook_secret' => 'wh-secret']);

    expect($account->api_username)->toBeNull()
        ->and($account->api_password)->toBeNull()
        ->and($account->webhook_secret)->toBeNull();

    $account->api_username = 'user-secret';
    $account->api_password = 'pass-secret';
    $account->webhook_secret = 'wh-secret';
    $account->save();

    $raw = DB::table('worldpay_accounts')->first();
    expect($raw->api_username)->not->toContain('user-secret')
        ->and($raw->api_password)->not->toContain('pass-secret')
        ->and($raw->webhook_secret)->not->toContain('wh-secret')
        ->and($account->fresh()->api_password)->toBe('pass-secret');
});

it('builds a Worldpay payment with its pages and attempts', function () {
    $payment = Payment::factory()->worldpay()->create();

    expect($payment->provider)->toBe(PaymentProvider::Worldpay)
        ->and($payment->currency)->toBe('gbp')
        ->and($payment->providerAccountName())->toBe($payment->worldpayAccount->account_name);

    $page = WorldpayPage::create([
        'payment_id' => $payment->id, 'sequence' => 1,
        'transaction_reference' => $payment->uuid.'-1', 'expires_at' => now()->addDay(),
    ]);
    WorldpayLookupAttempt::create([
        'payment_id' => $payment->id, 'page_id' => $page->id, 'source' => 'poll', 'outcome' => 'pending',
        'last_checked_at' => now(),
    ]);

    expect($payment->worldpayPages)->toHaveCount(1)
        ->and($payment->worldpayLookupAttempts)->toHaveCount(1)
        ->and(fn () => WorldpayPage::create([
            'payment_id' => $payment->id, 'sequence' => 1,
            'transaction_reference' => 'other', 'expires_at' => now(),
        ]))->toThrow(QueryException::class);
});
