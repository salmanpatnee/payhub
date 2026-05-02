<?php

use App\Models\Brand;
use App\Models\StripeAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('encrypts secret_key on save and decrypts correctly on read', function () {
    $plainKey = 'sk_test_placeholder_for_dev_only';

    $brand   = Brand::factory()->create();
    $account = StripeAccount::factory()->create([
        'brand_id'   => $brand->id,
        'secret_key' => $plainKey,
    ]);

    // Raw DB value must be ciphertext — NOT the original plain string
    $rawRow = DB::table('stripe_accounts')->where('id', $account->id)->value('secret_key');
    expect($rawRow)->not->toBe($plainKey);
    expect($rawRow)->not->toBeNull();

    // Fresh Eloquent fetch must decrypt back to the original value
    $fresh = StripeAccount::find($account->id);
    expect($fresh->secret_key)->toBe($plainKey);
});

it('encrypts webhook_secret on save and decrypts correctly on read', function () {
    $plainSecret = 'whsec_placeholder_for_dev_only';

    $brand   = Brand::factory()->create();
    $account = StripeAccount::factory()->create([
        'brand_id'       => $brand->id,
        'webhook_secret' => $plainSecret,
    ]);

    $rawRow = DB::table('stripe_accounts')->where('id', $account->id)->value('webhook_secret');
    expect($rawRow)->not->toBe($plainSecret);

    $fresh = StripeAccount::find($account->id);
    expect($fresh->webhook_secret)->toBe($plainSecret);
});
