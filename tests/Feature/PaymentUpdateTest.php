<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\SquareAccount;
use App\Models\StripeAccount;
use App\Models\User;
use App\Models\VivaAccount;
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

function updateSquarePayload(Brand $brand, SquareAccount $account, RelationshipManager $rm): array
{
    return [
        'brand_id' => $brand->id,
        'provider' => 'square',
        'account_id' => $account->id,
        'relationship_manager_id' => $rm->id,
        'currency' => $account->currency ?? 'usd',
        'amount' => '42.50',
        'client_name' => 'Updated Client',
        'client_email' => 'updated@example.com',
        'service' => 'Updated Service',
        'package' => 'premium',
        'note' => 'changed',
    ];
}

// Fixed gap: UpdatePaymentRequest previously never supported 'square' as a
// provider — editing a Square payment (or switching a payment to Square) was broken.
it('admin can update a payment to use a square account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create(['is_active' => true]);
    $squareAccount = SquareAccount::factory()->create(['is_active' => true, 'currency' => 'usd']);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", updateSquarePayload($brand, $squareAccount, $rm))
        ->assertRedirect("/payments/{$payment->uuid}")
        ->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->provider->value)->toBe('square');
    expect($payment->square_account_id)->toBe($squareAccount->id);
    expect($payment->stripe_account_id)->toBeNull();
    expect($payment->currency)->toBe('usd');
});

// Square accounts are single-currency: updating to a mismatched currency is rejected.
it('rejects updating a payment to a square account with a mismatched currency', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $squareAccount = SquareAccount::factory()->create(['is_active' => true, 'currency' => 'usd']);
    $rm = RelationshipManager::factory()->create();

    // Pin the currency: the factory picks usd/gbp at random, and the assertion below —
    // that the rejected 'gbp' never landed — is vacuous if the payment started as gbp.
    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'square_account_id' => $squareAccount->id,
        'provider' => 'square',
        'stripe_account_id' => null,
        'currency' => 'usd',
        'status' => 'pending',
    ]);

    $payload = updateSquarePayload($brand, $squareAccount, $rm);
    $payload['currency'] = 'gbp';

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", $payload)
        ->assertSessionHasErrors(['currency' => 'This Square account only accepts usd payments.']);

    expect($payment->fresh()->currency)->not()->toBe('gbp');
});

// A PaymentIntent belongs to the Stripe account that created it. Moving the payment to
// another account must drop the id, or the pay page 404s against the new account's key.
it('nulls stripe_payment_intent_id when the payment moves to another stripe account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $accountA = StripeAccount::factory()->create(['is_active' => true]);
    $accountB = StripeAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $accountA->id,
        'stripe_payment_intent_id' => 'pi_on_account_a',
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", updatePayload($brand, $accountB, $rm))
        ->assertRedirect("/payments/{$payment->uuid}")
        ->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->stripe_account_id)->toBe($accountB->id);
    expect($payment->stripe_payment_intent_id)->toBeNull();
});

it('nulls stripe_payment_intent_id when the payment switches provider to square', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create(['is_active' => true]);
    $squareAccount = SquareAccount::factory()->create(['is_active' => true, 'currency' => 'usd']);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'stripe_payment_intent_id' => 'pi_orphaned_by_provider_switch',
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", updateSquarePayload($brand, $squareAccount, $rm))
        ->assertRedirect("/payments/{$payment->uuid}")
        ->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->provider->value)->toBe('square');
    expect($payment->stripe_payment_intent_id)->toBeNull();
});

// The id is only cleared on an account move — an ordinary edit must not throw away a
// live PaymentIntent, or every save would orphan the one the client is looking at.
it('preserves stripe_payment_intent_id when the stripe account is unchanged', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'stripe_payment_intent_id' => 'pi_still_valid',
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", [...updatePayload($brand, $account, $rm), 'note' => 'just a note edit'])
        ->assertRedirect("/payments/{$payment->uuid}")
        ->assertSessionHasNoErrors();

    expect($payment->fresh()->stripe_payment_intent_id)->toBe('pi_still_valid');
});

// Agents have their account forced by agentAccountData(), which can move the payment
// off the account its PaymentIntent lives on.
it('nulls stripe_payment_intent_id when an agent update forces a different account', function () {
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
        'stripe_account_id' => $otherAccount->id,
        'stripe_payment_intent_id' => 'pi_on_other_account',
        'status' => 'pending',
    ]);

    $this->actingAs($agent)
        ->patch("/payments/{$payment->uuid}", updatePayload($brand, $otherAccount, $rm))
        ->assertRedirect();

    $payment->refresh();
    expect($payment->stripe_account_id)->toBe($agentAccount->id);
    expect($payment->stripe_payment_intent_id)->toBeNull();
});

function updateVivaPayload(Brand $brand, VivaAccount $account, RelationshipManager $rm): array
{
    return [
        'brand_id' => $brand->id,
        'provider' => 'viva',
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

it('admin can update a payment to use a viva account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create(['is_active' => true]);
    $vivaAccount = VivaAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", updateVivaPayload($brand, $vivaAccount, $rm))
        ->assertRedirect("/payments/{$payment->uuid}")
        ->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->provider->value)->toBe('viva');
    expect($payment->viva_account_id)->toBe($vivaAccount->id);
    expect($payment->stripe_account_id)->toBeNull();
    expect($payment->currency)->toBe('gbp');
});

// Viva is GBP-only as a flat platform rule: updating to a non-GBP currency is rejected.
it('rejects updating a payment to a viva account with a non-gbp currency', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $vivaAccount = VivaAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'viva_account_id' => $vivaAccount->id,
        'provider' => 'viva',
        'stripe_account_id' => null,
        'currency' => 'gbp',
        'status' => 'pending',
    ]);

    $payload = updateVivaPayload($brand, $vivaAccount, $rm);
    $payload['currency'] = 'usd';

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", $payload)
        ->assertSessionHasErrors(['currency' => 'Viva payments must be in GBP.']);

    expect($payment->fresh()->currency)->not()->toBe('usd');
});

// Fixed gap: clearStaleProviderTransactionIds() previously had no viva_account_id
// mapping, so moving a Viva payment to another account left the old order code and
// transaction id dangling (both scoped to the account that created the order).
it('nulls viva_order_code and viva_transaction_id when the payment moves to another viva account', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $vivaAccountA = VivaAccount::factory()->create(['is_active' => true]);
    $vivaAccountB = VivaAccount::factory()->create(['is_active' => true]);
    $rm = RelationshipManager::factory()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'viva_account_id' => $vivaAccountA->id,
        'provider' => 'viva',
        'stripe_account_id' => null,
        'currency' => 'gbp',
        'viva_order_code' => 'order_on_account_a',
        'viva_transaction_id' => 'txn_on_account_a',
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->patch("/payments/{$payment->uuid}", updateVivaPayload($brand, $vivaAccountB, $rm))
        ->assertRedirect("/payments/{$payment->uuid}")
        ->assertSessionHasNoErrors();

    $payment->refresh();
    expect($payment->viva_account_id)->toBe($vivaAccountB->id);
    expect($payment->viva_order_code)->toBeNull();
    expect($payment->viva_transaction_id)->toBeNull();
});
