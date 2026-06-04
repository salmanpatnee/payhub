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
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

function validPaymentPayload(Brand $brand, StripeAccount $account): array
{
    $rm = RelationshipManager::factory()->create();

    return [
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'relationship_manager_id' => $rm->id,
        'currency' => 'usd',
        'amount' => '25.00',
        'client_name' => 'Alice Smith',
        'client_email' => 'alice@example.com',
        'service' => 'Web Design',
        'package' => 'standard',
        'note' => null,
    ];
}

// PAY-01: Admin can create a payment with all required fields
it('admin can create a payment record with all fields', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $response = $this->actingAs($admin)
        ->post('/payments', validPaymentPayload($brand, $account));

    $payment = Payment::first();
    $response->assertRedirect("/payments/{$payment->uuid}");
    expect(Payment::count())->toBe(1);
    expect($payment->brand_id)->toBe($brand->id);
    expect($payment->stripe_account_id)->toBe($account->id);
    expect($payment->status)->toBe('pending');
    expect($payment->user_id)->toBe($admin->id);
});

// PAY-01: User (non-admin) can also create a payment
it('non-admin user can create a payment record', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $response = $this->actingAs($user)
        ->post('/payments', validPaymentPayload($brand, $account));

    $payment = Payment::first();
    $response->assertRedirect("/payments/{$payment->uuid}");
    expect(Payment::count())->toBe(1);
    expect($payment->user_id)->toBe($user->id);
});

// PAY-02: Only active Stripe accounts are accepted — inactive rejected with validation error
it('rejects inactive stripe account with validation error', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => false]);

    $this->actingAs($user)
        ->post('/payments', validPaymentPayload($brand, $account))
        ->assertSessionHasErrors('stripe_account_id');

    expect(Payment::count())->toBe(0);
});

// PAY-03: client_name and client_email are stored on the Payment record
it('stores client name and email on the payment record', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $this->actingAs($user)->post('/payments', validPaymentPayload($brand, $account));

    $payment = Payment::first();
    expect($payment->client_name)->toBe('Alice Smith');
    expect($payment->client_email)->toBe('alice@example.com');
});

// PAY-04: UUID is generated and show page is accessible at /payments/{uuid}
it('generates a uuid and redirects to show page after creation', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $this->actingAs($user)->post('/payments', validPaymentPayload($brand, $account));

    $payment = Payment::first();
    expect($payment->uuid)->not()->toBeEmpty();

    $response = $this->actingAs($user)->get("/payments/{$payment->uuid}");
    $response->assertStatus(200);
});

// PAY-05 + SEC-02: Amount stored as integer cents from decimal input
it('converts decimal amount to integer cents and stores server-side', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $payload = validPaymentPayload($brand, $account);
    $payload['amount'] = '25.00';

    $this->actingAs($user)->post('/payments', $payload);

    $payment = Payment::first();
    expect($payment->amount)->toBe(2500);
    expect($payment->amount)->toBeInt();
});

// PAY-06: Only usd and gbp are accepted; other currencies rejected
it('accepts usd and gbp currencies and rejects others', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $payload = validPaymentPayload($brand, $account);
    $payload['currency'] = 'gbp';
    $this->actingAs($user)->post('/payments', $payload)->assertRedirect();

    Payment::query()->delete();

    $payload['currency'] = 'eur';
    $this->actingAs($user)->post('/payments', $payload)
        ->assertSessionHasErrors('currency');
    expect(Payment::count())->toBe(0);
});

// PAY-07: expires_at is null after creation
it('sets expires_at to null on payment creation', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $this->actingAs($user)->post('/payments', validPaymentPayload($brand, $account));

    expect(Payment::first()->expires_at)->toBeNull();
});

// Role-scoped index: Admin sees all payments, User sees only own
it('admin sees all payments on index and user sees only own', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $user = User::factory()->create();
    $user->assignRole('user');

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'client_name' => 'Admin Client',
    ]);
    Payment::factory()->create([
        'user_id' => $user->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'client_name' => 'User Client',
    ]);

    $this->actingAs($admin)->get('/payments')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->has('payments.data', 2));

    $this->actingAs($user)->get('/payments')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->has('payments.data', 1));
});

// Agent sees only their mapped brands and relationship managers on the create page
it('agent create page shows only mapped brands and relationship managers', function () {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $account = StripeAccount::factory()->create(['is_active' => true]);
    $agent->stripe_account_id = $account->id;
    $agent->save();

    $mappedBrand = Brand::factory()->create();
    $otherBrand = Brand::factory()->create();
    $mappedRm = RelationshipManager::factory()->create();
    RelationshipManager::factory()->create();

    $agent->brands()->sync([$mappedBrand->id]);
    $agent->relationshipManagers()->sync([$mappedRm->id]);

    $this->actingAs($agent)->get('/payments/create')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->has('brands', 1)
            ->has('relationshipManagers', 1)
            ->where('brands.0.id', $mappedBrand->id)
            ->where('relationshipManagers.0.id', $mappedRm->id)
        );
});

// Agent cannot create a payment against a brand outside their mapping (SEC: horizontal escalation)
it('agent cannot create a payment with an unmapped brand', function () {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $account = StripeAccount::factory()->create(['is_active' => true]);
    $agent->stripe_account_id = $account->id;
    $agent->save();

    $mappedBrand = Brand::factory()->create();
    $unmappedBrand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();

    $agent->brands()->sync([$mappedBrand->id]);
    $agent->relationshipManagers()->sync([$rm->id]);

    $payload = validPaymentPayload($unmappedBrand, $account);
    $payload['relationship_manager_id'] = $rm->id;

    $this->actingAs($agent)->post('/payments', $payload)
        ->assertSessionHasErrors('brand_id');

    expect(Payment::count())->toBe(0);
});

// Agent cannot create a payment against a relationship manager outside their mapping
it('agent cannot create a payment with an unmapped relationship manager', function () {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $account = StripeAccount::factory()->create(['is_active' => true]);
    $agent->stripe_account_id = $account->id;
    $agent->save();

    $brand = Brand::factory()->create();
    $mappedRm = RelationshipManager::factory()->create();
    $unmappedRm = RelationshipManager::factory()->create();

    $agent->brands()->sync([$brand->id]);
    $agent->relationshipManagers()->sync([$mappedRm->id]);

    $payload = validPaymentPayload($brand, $account);
    $payload['relationship_manager_id'] = $unmappedRm->id;

    $this->actingAs($agent)->post('/payments', $payload)
        ->assertSessionHasErrors('relationship_manager_id');

    expect(Payment::count())->toBe(0);
});

// Agent can create a payment against a mapped brand and relationship manager
it('agent can create a payment with mapped brand and relationship manager', function () {
    $agent = User::factory()->create();
    $agent->assignRole('agent');

    $account = StripeAccount::factory()->create(['is_active' => true]);
    $agent->stripe_account_id = $account->id;
    $agent->save();

    $brand = Brand::factory()->create();
    $rm = RelationshipManager::factory()->create();

    $agent->brands()->sync([$brand->id]);
    $agent->relationshipManagers()->sync([$rm->id]);

    $payload = validPaymentPayload($brand, $account);
    $payload['relationship_manager_id'] = $rm->id;

    $this->actingAs($agent)->post('/payments', $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(Payment::count())->toBe(1);
    expect(Payment::first()->brand_id)->toBe($brand->id);
});

// RM deactivation: admin create page excludes inactive RMs from selection
it('admin create page excludes inactive relationship managers', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $active = RelationshipManager::factory()->create();
    RelationshipManager::factory()->inactive()->create();

    $this->actingAs($admin)->get('/payments/create')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->has('relationshipManagers', 1)
            ->where('relationshipManagers.0.id', $active->id)
        );
});

// RM deactivation: editing a payment whose RM is inactive still lists that RM
it('payment edit page includes the currently-assigned inactive relationship manager', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);
    $inactiveRm = RelationshipManager::factory()->inactive()->create();

    $payment = Payment::factory()->create([
        'user_id' => $admin->id,
        'brand_id' => $brand->id,
        'stripe_account_id' => $account->id,
        'relationship_manager_id' => $inactiveRm->id,
        'status' => 'pending',
    ]);

    $this->actingAs($admin)->get("/payments/{$payment->uuid}/edit")
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->has('relationshipManagers', 1)
            ->where('relationshipManagers.0.id', $inactiveRm->id)
        );
});

// RM deactivation: index filter dropdown still lists inactive RMs (historical filtering)
it('payment index filter still lists inactive relationship managers', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    RelationshipManager::factory()->create();
    RelationshipManager::factory()->inactive()->create();

    $this->actingAs($admin)->get('/payments')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->has('relationshipManagers', 2));
});

// SEC-02: Amount in database matches round of submitted decimal times 100
it('amount in database matches round of submitted decimal times 100', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $brand = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['is_active' => true]);

    $payload = validPaymentPayload($brand, $account);
    $payload['amount'] = '10.005';

    $this->actingAs($user)->post('/payments', $payload);

    expect(Payment::first()->amount)->toBe(1001);
    expect(Payment::first()->amount)->toBeInt();
});
