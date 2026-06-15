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
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

function updatePayload(Brand $brand, StripeAccount $account, RelationshipManager $rm): array
{
    return [
        'brand_id' => $brand->id,
        'provider' => 'stripe',
        'account_id' => $account->id,
        'relationship_manager_id' => $rm->id,
        'currency' => 'gbp',
        'amount' => '42.50',
        'client_name' => 'Updated Client',
        'client_email' => 'updated@example.com',
        'service' => 'Updated Service',
        'package' => 'premium',
        'note' => 'changed',
    ];
}

it('admin can update a pending payment and amount persists as cents', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", updatePayload($brand, $account, $rm))
        ->assertRedirect("/payments/{$payment->uuid}")
        ->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->amount)->toBe(4250);
    expect($payment->currency)->toBe('gbp');
    expect($payment->client_name)->toBe('Updated Client');
    expect($payment->relationship_manager_id)->toBe($rm->id);
});

it('edit page is forbidden for non-pending payments', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'status' => 'completed',
    ]);

    $this->actingAs($admin)->get("/payments/{$payment->uuid}/edit")->assertForbidden();
});

it('update is blocked when payment is not pending', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'status' => 'completed',
        'amount' => 999,
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", updatePayload($brand, $account, $rm))
        ->assertForbidden();

    expect($payment->fresh()->amount)->toBe(999);
});

it('creating agent can update their own pending payment', function () {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $account = StripeAccount::factory()->create(['is_active' => true]);
    $agent->stripe_account_id = $account->id;
    $agent->save();

    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent->brands()->sync([$brand->id]);
    $agent->relationshipManagers()->sync([$rm->id]);

    $payment = Payment::factory()->create([
        'user_id' => $agent->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'status' => 'pending',
    ]);

    $this->actingAs($agent)
        ->patch("/payments/{$payment->uuid}", updatePayload($brand, $account, $rm))
        ->assertRedirect("/payments/{$payment->uuid}")
        ->assertSessionHasNoErrors();

    expect($payment->fresh()->client_name)->toBe('Updated Client');
});

it('agent cannot update a payment they did not create', function () {
    $owner = User::factory()->create();
    $owner->assignRole('agent');
    $other = User::factory()->create();
    $other->assignRole('agent');

    $account = StripeAccount::factory()->create(['is_active' => true]);
    $other->stripe_account_id = $account->id;
    $other->save();

    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $other->brands()->sync([$brand->id]);
    $other->relationshipManagers()->sync([$rm->id]);

    $payment = Payment::factory()->create([
        'user_id' => $owner->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'status' => 'pending',
    ]);

    $this->actingAs($other)
        ->patch("/payments/{$payment->uuid}", updatePayload($brand, $account, $rm))
        ->assertForbidden();
});

it('agent stripe_account is forced on update regardless of submitted value', function () {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $agentAccount = StripeAccount::factory()->create(['is_active' => true]);
    $otherAccount = StripeAccount::factory()->create(['is_active' => true]);
    $agent->stripe_account_id = $agentAccount->id;
    $agent->save();

    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();
    $agent->brands()->sync([$brand->id]);
    $agent->relationshipManagers()->sync([$rm->id]);

    $payment = Payment::factory()->create([
        'user_id' => $agent->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $agentAccount->id,
        'status' => 'pending',
    ]);

    $payload = updatePayload($brand, $otherAccount, $rm);

    $this->actingAs($agent)
        ->patch("/payments/{$payment->uuid}", $payload)
        ->assertRedirect();

    expect($payment->fresh()->stripe_account_id)->toBe($agentAccount->id);
});
