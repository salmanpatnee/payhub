<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
});

it('assigns sequential reference codes starting at 1001', function () {
    $first = Payment::factory()->create();
    $second = Payment::factory()->create();

    expect($first->reference_code)->toBe(1001)
        ->and($second->reference_code)->toBe(1002);
});

it('never assigns duplicate reference codes across many creates', function () {
    $codes = collect(range(1, 20))
        ->map(fn () => Payment::factory()->create()->reference_code);

    expect($codes->unique()->count())->toBe(20)
        ->and($codes->min())->toBe(1001)
        ->and($codes->max())->toBe(1020);
});

it('does not reuse a soft-deleted reference code', function () {
    $deleted = Payment::factory()->create();
    expect($deleted->reference_code)->toBe(1001);
    $deleted->delete(); // soft delete — row + unique index entry remain

    // The unique index still holds 1001, so the next code must skip past it.
    $next = Payment::factory()->create();

    expect($next->reference_code)->toBe(1002);
});

it('creates payments with consecutive reference codes through the controller', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $payload = [
        'brand_id' => $brand->id,
        'provider' => 'stripe',
        'account_id' => $account->id,
        'relationship_manager_id' => $rm->id,
        'currency' => 'usd',
        'amount' => '25.00',
        'client_name' => 'Alice Smith',
        'client_email' => 'alice@example.com',
        'service' => 'Web Design',
        'package' => 'standard',
        'note' => null,
    ];

    $this->actingAs($user)->post('/payments', $payload)->assertRedirect();
    $this->actingAs($user)->post('/payments', $payload)->assertRedirect();

    $codes = Payment::orderBy('id')->pluck('reference_code');

    expect($codes->toArray())->toBe([1001, 1002]);
});
