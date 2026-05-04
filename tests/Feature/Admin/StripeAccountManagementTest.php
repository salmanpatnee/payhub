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
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // STRIPE-01: Admin can create a stripe account linked to a brand
    public function test_admin_can_create_stripe_account(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // STRIPE-01: brand_id comes from route binding, not request body
    public function test_brand_id_comes_from_route_not_request(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // STRIPE-04: Admin can update a stripe account (blank secret_key keeps existing)
    public function test_admin_can_update_stripe_account_without_changing_key(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // STRIPE-04: Admin can update a stripe account with a new secret_key
    public function test_admin_can_update_stripe_account_with_new_key(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // STRIPE-05: Admin can deactivate a stripe account (is_active = false, not deleted)
    public function test_admin_can_deactivate_stripe_account(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // STRIPE-05: Deactivated account is not deleted from the database
    public function test_deactivated_account_remains_in_database(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // BRAND-04: Test-mode publishable key is rejected in production environment
    public function test_test_publishable_key_blocked_in_production(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // BRAND-04: Test-mode secret key is rejected in production environment
    public function test_test_secret_key_blocked_in_production(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // STRIPE-03: Invalid key pair is rejected with a stripe_api validation error
    public function test_invalid_stripe_key_pair_is_rejected(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend (requires StripeClient mock).');
    }

    // Access control: non-admin cannot access stripe account routes
    public function test_non_admin_cannot_access_stripe_accounts(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // Scoped binding: cannot access stripe account from a different brand
    public function test_cannot_access_stripe_account_from_wrong_brand(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }
}
