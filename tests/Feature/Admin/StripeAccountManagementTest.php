<?php

namespace Tests\Feature\Admin;

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

    // STRIPE-01: Admin can view all stripe accounts
    public function test_admin_can_view_stripe_accounts(): void
    {
        StripeAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.stripe-accounts.index'))
            ->assertOk();
    }

    // STRIPE-01: Admin can create a stripe account
    public function test_admin_can_create_stripe_account(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.stripe-accounts.store'), [
                'account_name'    => 'Test Account',
                'publishable_key' => 'pk_test_abc123',
                'secret_key'      => 'sk_test_abc123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.stripe-accounts.index'));

        $this->assertDatabaseHas('stripe_accounts', [
            'account_name' => 'Test Account',
        ]);
    }

    // STRIPE-04: Admin can update a stripe account (blank secret_key keeps existing)
    public function test_admin_can_update_stripe_account_without_changing_key(): void
    {
        $account = StripeAccount::factory()->create(['account_name' => 'Old Name']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.stripe-accounts.update', $account), [
                'account_name'    => 'New Name',
                'publishable_key' => $account->publishable_key,
                'secret_key'      => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.stripe-accounts.index'));

        $this->assertDatabaseHas('stripe_accounts', [
            'id'           => $account->id,
            'account_name' => 'New Name',
        ]);
    }

    // STRIPE-04: Admin can update a stripe account with a new secret_key
    public function test_admin_can_update_stripe_account_with_new_key(): void
    {
        $account = StripeAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->put(route('admin.stripe-accounts.update', $account), [
                'account_name'    => $account->account_name,
                'publishable_key' => 'pk_test_newkey123',
                'secret_key'      => 'sk_test_newkey123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.stripe-accounts.index'));
    }

    // STRIPE-05: Admin can deactivate a stripe account (is_active = false, not deleted)
    public function test_admin_can_deactivate_stripe_account(): void
    {
        $account = StripeAccount::factory()->create(['is_active' => true]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.stripe-accounts.deactivate', $account))
            ->assertRedirect(route('admin.stripe-accounts.index'));

        $this->assertDatabaseHas('stripe_accounts', [
            'id'        => $account->id,
            'is_active' => false,
        ]);
    }

    // STRIPE-05: Deactivated account is not deleted from the database
    public function test_deactivated_account_remains_in_database(): void
    {
        $account = StripeAccount::factory()->create(['is_active' => true]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.stripe-accounts.deactivate', $account));

        $this->assertDatabaseHas('stripe_accounts', ['id' => $account->id]);
    }

    // Admin can hard-delete an account with no payments
    public function test_admin_can_delete_stripe_account_with_no_payments(): void
    {
        $account = StripeAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->delete(route('admin.stripe-accounts.destroy', $account))
            ->assertRedirect(route('admin.stripe-accounts.index'));

        $this->assertDatabaseMissing('stripe_accounts', ['id' => $account->id]);
    }

    // BRAND-04: Test-mode publishable key is rejected in production environment
    public function test_test_publishable_key_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->actingAs($this->adminUser())
            ->withSession(['_token' => 'test_token'])
            ->post(route('admin.stripe-accounts.store'), [
                'account_name'    => 'Test Account',
                'publishable_key' => 'pk_test_abc123',
                'secret_key'      => 'sk_live_abc123',
                '_token'          => 'test_token',
            ])
            ->assertSessionHasErrors(['publishable_key']);
    }

    // BRAND-04: Test-mode secret key is rejected in production environment
    public function test_test_secret_key_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->actingAs($this->adminUser())
            ->withSession(['_token' => 'test_token'])
            ->post(route('admin.stripe-accounts.store'), [
                'account_name'    => 'Test Account',
                'publishable_key' => 'pk_live_abc123',
                'secret_key'      => 'sk_test_abc123',
                '_token'          => 'test_token',
            ])
            ->assertSessionHasErrors(['secret_key']);
    }

    // STRIPE-03: Invalid key pair is rejected with a stripe_api validation error
    // NOTE: validateStripeKeyPair() is skipped in testing environment — manual verification step.
    public function test_invalid_stripe_key_pair_is_rejected(): void
    {
        $this->markTestIncomplete('STRIPE-03: Stripe API validation is a manual verification step — see VALIDATION.md');
    }

    // Access control: non-admin cannot access stripe account routes
    public function test_non_admin_cannot_access_stripe_accounts(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles(['user']);

        $this->actingAs($user)
            ->get(route('admin.stripe-accounts.index'))
            ->assertForbidden();
    }
}
