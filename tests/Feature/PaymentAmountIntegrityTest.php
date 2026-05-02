<?php

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores amount as integer cents and retrieves without float drift', function () {
    $payment = Payment::factory()->create(['amount' => 999]);
    $fresh   = Payment::find($payment->id);

    expect($fresh->amount)->toBe(999);
    expect($fresh->amount)->toBeInt();
});

it('stores large amounts without drift', function () {
    $payment = Payment::factory()->create(['amount' => 100000]);
    $fresh   = Payment::find($payment->id);

    expect($fresh->amount)->toBe(100000);
    expect($fresh->amount)->toBeInt();
});

it('stores minimum valid amount without drift', function () {
    $payment = Payment::factory()->create(['amount' => 50]);
    $fresh   = Payment::find($payment->id);

    expect($fresh->amount)->toBe(50);
    expect($fresh->amount)->toBeInt();
});
