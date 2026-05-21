<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

function makePaymentForOwner(User $owner): Payment
{
    return Payment::factory()
        ->for(Brand::factory()->create())
        ->for(StripeAccount::factory()->create(), 'stripeAccount')
        ->create(['user_id' => $owner->id]);
}

// H1 fix: agent cannot view another agent's payment
it('agent cannot view a payment they do not own', function () {
    $agent1 = User::factory()->create()->assignRole('agent');
    $agent2 = User::factory()->create()->assignRole('agent');

    $payment = makePaymentForOwner($agent1);

    $this->actingAs($agent2)
        ->get(route('payments.show', $payment))
        ->assertForbidden();
});

// H1 fix: agent can view their own payment
it('agent can view their own payment', function () {
    $agent = User::factory()->create()->assignRole('agent');
    $payment = makePaymentForOwner($agent);

    $this->actingAs($agent)
        ->get(route('payments.show', $payment))
        ->assertOk();
});

// H1 fix: admin can view any payment regardless of owner
it('admin can view any payment regardless of owner', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $otherAgent = User::factory()->create()->assignRole('agent');

    $payment = makePaymentForOwner($otherAgent);

    $this->actingAs($admin)
        ->get(route('payments.show', $payment))
        ->assertOk();
});
