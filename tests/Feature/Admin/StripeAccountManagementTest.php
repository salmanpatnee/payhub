<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StripeAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles(['admin']);
        return $admin;
    }

    // STRIPE-01: Admin can view stripe accounts for a brand
    public function test_admin_can_view_stripe_accounts_for_brand(): void
    {
        $brand   = Brand::factory()->create();
        $account = StripeAccount::factory()->create(['brand_id' => $brand->id]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.brands.stripe-accounts.index', $brand))
            ->assertOk();
    }

    // STRIPE-01: Admin can create a stripe account linked to a brand
    public function test_admin_can_create_stripe_account(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs($this->adminUser())
            ->post(route('admin.brands.stripe-accounts.store', $brand), [
                'account_name'    => 'Test Account',
                'publishable_key' => 'pk_test_abc123',
                'secret_key'      => 'sk_test_abc123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.brands.stripe-accounts.index', $brand));

        $this->assertDatabaseHas('stripe_accounts', [
            'account_name' => 'Test Account',
            'brand_id'     => $brand->id,
        ]);
    }

    // STRIPE-01: brand_id comes from route binding, not request body
    public function test_brand_id_comes_from_route_not_request(): void
    {
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();

        // Post to brandA's store but include brandB's id in body — should still save to brandA
        $this->actingAs($this->adminUser())
            ->post(route('admin.brands.stripe-accounts.store', $brandA), [
                'account_name'    => 'Injected Account',
                'publishable_key' => 'pk_test_abc123',
                'secret_key'      => 'sk_test_abc123',
                'brand_id'        => $brandB->id,  // attempted injection
            ])
            ->assertSessionHasNoErrors();

        // Must be saved under brandA, not brandB
        $this->assertDatabaseHas('stripe_accounts', [
            'account_name' => 'Injected Account',
            'brand_id'     => $brandA->id,
        ]);
    }

    // STRIPE-04: Admin can update a stripe account (blank secret_key keeps existing)
    public function test_admin_can_update_stripe_account_without_changing_key(): void
    {
        $brand   = Brand::factory()->create();
        $account = StripeAccount::factory()->create([
            'brand_id'     => $brand->id,
            'account_name' => 'Old Name',
        ]);

        $this->actingAs($this->adminUser())
            ->put(route('admin.brands.stripe-accounts.update', [$brand, $account]), [
                'account_name'    => 'New Name',
                'publishable_key' => $account->publishable_key,
                'secret_key'      => '',  // blank = keep existing
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.brands.stripe-accounts.index', $brand));

        $this->assertDatabaseHas('stripe_accounts', [
            'id'           => $account->id,
            'account_name' => 'New Name',
        ]);
    }

    // STRIPE-04: Admin can update a stripe account with a new secret_key
    public function test_admin_can_update_stripe_account_with_new_key(): void
    {
        $brand   = Brand::factory()->create();
        $account = StripeAccount::factory()->create(['brand_id' => $brand->id]);

        $this->actingAs($this->adminUser())
            ->put(route('admin.brands.stripe-accounts.update', [$brand, $account]), [
                'account_name'    => $account->account_name,
                'publishable_key' => 'pk_test_newkey123',
                'secret_key'      => 'sk_test_newkey123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.brands.stripe-accounts.index', $brand));
    }

    // STRIPE-05: Admin can deactivate a stripe account (is_active = false, not deleted)
    public function test_admin_can_deactivate_stripe_account(): void
    {
        $brand   = Brand::factory()->create();
        $account = StripeAccount::factory()->create([
            'brand_id'  => $brand->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.brands.stripe-accounts.deactivate', [$brand, $account]))
            ->assertRedirect(route('admin.brands.stripe-accounts.index', $brand));

        $this->assertDatabaseHas('stripe_accounts', [
            'id'        => $account->id,
            'is_active' => false,
        ]);
    }

    // STRIPE-05: Deactivated account is not deleted from the database
    public function test_deactivated_account_remains_in_database(): void
    {
        $brand   = Brand::factory()->create();
        $account = StripeAccount::factory()->create(['brand_id' => $brand->id, 'is_active' => true]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.brands.stripe-accounts.deactivate', [$brand, $account]));

        $this->assertDatabaseHas('stripe_accounts', ['id' => $account->id]);
    }

    // BRAND-04: Test-mode publishable key is rejected in production environment
    public function test_test_publishable_key_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');
        $brand = Brand::factory()->create();

        $response = $this->actingAs($this->adminUser())
            ->withSession(['_token' => 'test_token'])
            ->post(route('admin.brands.stripe-accounts.store', $brand), [
                'account_name'    => 'Test Account',
                'publishable_key' => 'pk_test_abc123',
                'secret_key'      => 'sk_live_abc123',
                '_token'          => 'test_token',
            ]);

        $response->assertSessionHasErrors(['publishable_key']);
    }

    // BRAND-04: Test-mode secret key is rejected in production environment
    public function test_test_secret_key_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');
        $brand = Brand::factory()->create();

        $this->actingAs($this->adminUser())
            ->withSession(['_token' => 'test_token'])
            ->post(route('admin.brands.stripe-accounts.store', $brand), [
                'account_name'    => 'Test Account',
                'publishable_key' => 'pk_live_abc123',
                'secret_key'      => 'sk_test_abc123',
                '_token'          => 'test_token',
            ])
            ->assertSessionHasErrors(['secret_key']);
    }

    // STRIPE-03: Invalid key pair is rejected with a stripe_api validation error
    // NOTE: validateStripeKeyPair() is skipped in testing environment (STRIPE-03 is a manual verification step).
    // This test is kept as incomplete — see VALIDATION.md for manual verification steps.
    public function test_invalid_stripe_key_pair_is_rejected(): void
    {
        $this->markTestIncomplete('STRIPE-03: Stripe API validation is a manual verification step — see VALIDATION.md');
    }

    // Access control: non-admin cannot access stripe account routes
    public function test_non_admin_cannot_access_stripe_accounts(): void
    {
        $brand = Brand::factory()->create();
        $user  = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles(['user']);

        $this->actingAs($user)
            ->get(route('admin.brands.stripe-accounts.index', $brand))
            ->assertForbidden();
    }

    // Scoped binding: cannot access stripe account from a different brand
    public function test_cannot_access_stripe_account_from_wrong_brand(): void
    {
        $brandA  = Brand::factory()->create();
        $brandB  = Brand::factory()->create();
        $account = StripeAccount::factory()->create(['brand_id' => $brandA->id]);

        // Try to access brandA's account via brandB's route — scoped binding should 404
        $this->actingAs($this->adminUser())
            ->get(route('admin.brands.stripe-accounts.edit', [$brandB, $account]))
            ->assertNotFound();
    }
}
