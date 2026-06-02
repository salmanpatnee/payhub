<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('payment belongs to brand, stripe account, and user', function () {
    $brand = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create();
    $user = User::factory()->create();

    $payment = Payment::factory()->create([
        'brand_id' => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'user_id' => $user->id,
    ]);

    expect($payment->brand->id)->toBe($brand->id);
    expect($payment->stripeAccount->id)->toBe($stripeAccount->id);
    expect($payment->user->id)->toBe($user->id);
});

it('brand has many payments', function () {
    $brand = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create();
    $user = User::factory()->create();

    Payment::factory()->count(3)->create([
        'brand_id' => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'user_id' => $user->id,
    ]);

    expect($brand->payments)->toHaveCount(3);
});

it('user maps to many brands and relationship managers', function () {
    $user = User::factory()->create();
    $brands = Brand::factory()->count(2)->create();
    $rms = RelationshipManager::factory()->count(3)->create();

    $user->brands()->sync($brands->pluck('id'));
    $user->relationshipManagers()->sync($rms->pluck('id'));

    expect($user->brands)->toHaveCount(2);
    expect($user->relationshipManagers)->toHaveCount(3);
    expect($brands->first()->users->pluck('id'))->toContain($user->id);
    expect($rms->first()->users->pluck('id'))->toContain($user->id);
});
