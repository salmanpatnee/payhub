<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('brand has many stripe accounts', function () {
    $brand          = Brand::factory()->create();
    $stripeAccount  = StripeAccount::factory()->create(['brand_id' => $brand->id]);

    expect($brand->stripeAccounts)->toHaveCount(1);
    expect($brand->stripeAccounts->first()->id)->toBe($stripeAccount->id);
});

it('stripe account belongs to brand', function () {
    $brand         = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create(['brand_id' => $brand->id]);

    expect($stripeAccount->brand->id)->toBe($brand->id);
});

it('payment belongs to brand, stripe account, and user', function () {
    $brand         = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create(['brand_id' => $brand->id]);
    $user          = User::factory()->create();

    $payment = Payment::factory()->create([
        'brand_id'          => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'user_id'           => $user->id,
    ]);

    expect($payment->brand->id)->toBe($brand->id);
    expect($payment->stripeAccount->id)->toBe($stripeAccount->id);
    expect($payment->user->id)->toBe($user->id);
});

it('brand has many payments', function () {
    $brand         = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create(['brand_id' => $brand->id]);
    $user          = User::factory()->create();

    Payment::factory()->count(3)->create([
        'brand_id'          => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'user_id'           => $user->id,
    ]);

    expect($brand->payments)->toHaveCount(3);
});
