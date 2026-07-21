<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\SquareAccount;
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

it('square payment belongs to a square account and user has a square payment account', function () {
    $squareAccount = SquareAccount::factory()->create();
    $user = User::factory()->create();
    $user->paymentAccounts()->create(['currency' => 'usd', 'provider' => 'square', 'account_id' => $squareAccount->id]);

    $payment = Payment::factory()->square()->create([
        'square_account_id' => $squareAccount->id,
        'user_id' => $user->id,
    ]);

    expect($payment->squareAccount->id)->toBe($squareAccount->id);
    expect($payment->stripe_account_id)->toBeNull();
    expect($user->paymentAccounts->first()->account_id)->toBe($squareAccount->id);
    expect($squareAccount->payments->pluck('id'))->toContain($payment->id);
});

it('account_name accessor resolves per provider', function () {
    $stripeAccount = StripeAccount::factory()->create(['account_name' => 'Stripe Co']);
    $squareAccount = SquareAccount::factory()->create(['account_name' => 'Square Co']);

    $stripePayment = Payment::factory()->create(['stripe_account_id' => $stripeAccount->id]);
    $squarePayment = Payment::factory()->square()->create(['square_account_id' => $squareAccount->id]);

    expect($stripePayment->account_name)->toBe('Stripe Co');
    expect($squarePayment->account_name)->toBe('Square Co');
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
