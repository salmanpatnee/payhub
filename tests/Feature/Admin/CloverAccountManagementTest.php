<?php

namespace Tests\Feature\Admin;

use App\Models\CloverAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CloverAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles(['admin']);

        return $admin;
    }

    public function test_admin_can_view_clover_accounts(): void
    {
        CloverAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.clover-accounts.index'))
            ->assertOk();
    }

    public function test_admin_can_create_clover_account(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.clover-accounts.store'), [
                'account_name' => 'Test Clover Account',
                'merchant_id' => 'test-merchant-id',
                'api_access_key' => 'test-api-access-key',
                'private_token' => 'test-private-token',
                'environment' => 'sandbox',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.clover-accounts.index'));

        $this->assertDatabaseHas('clover_accounts', [
            'account_name' => 'Test Clover Account',
            'api_access_key' => 'test-api-access-key',
            'environment' => 'sandbox',
            'currency' => 'usd',
        ]);
    }

    public function test_admin_can_update_clover_account_without_changing_private_token(): void
    {
        $account = CloverAccount::factory()->create(['account_name' => 'Old Name']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.clover-accounts.update', $account), [
                'account_name' => 'New Name',
                'merchant_id' => $account->merchant_id,
                'api_access_key' => $account->api_access_key,
                'environment' => $account->environment,
                'private_token' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.clover-accounts.index'));

        $this->assertDatabaseHas('clover_accounts', [
            'id' => $account->id,
            'account_name' => 'New Name',
        ]);
    }

    public function test_blank_private_token_on_update_preserves_existing(): void
    {
        $account = CloverAccount::factory()->create();
        $account->private_token = 'original_private_token';
        $account->save();

        $this->actingAs($this->adminUser())
            ->put(route('admin.clover-accounts.update', $account), [
                'account_name' => $account->account_name,
                'merchant_id' => $account->merchant_id,
                'api_access_key' => $account->api_access_key,
                'environment' => $account->environment,
                'private_token' => '',
            ])
            ->assertSessionHasNoErrors();

        $account->refresh();
        expect($account->private_token)->toBe('original_private_token');
    }

    public function test_admin_can_update_clover_account_with_new_private_token(): void
    {
        $account = CloverAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->put(route('admin.clover-accounts.update', $account), [
                'account_name' => $account->account_name,
                'merchant_id' => 'new-merchant-id',
                'api_access_key' => 'new-api-access-key',
                'environment' => $account->environment,
                'private_token' => 'new_private_token',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.clover-accounts.index'));

        $account->refresh();
        expect($account->merchant_id)->toBe('new-merchant-id');
        expect($account->api_access_key)->toBe('new-api-access-key');
        expect($account->private_token)->toBe('new_private_token');
    }

    public function test_edit_returns_api_access_key_and_has_private_token_bool_never_raw_token(): void
    {
        $account = CloverAccount::factory()->create();
        $account->private_token = 'private_token_value';
        $account->save();

        $this->actingAs($this->adminUser())
            ->get(route('admin.clover-accounts.edit', $account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('cloverAccount.api_access_key')
                ->where('cloverAccount.api_access_key', $account->api_access_key)
                ->has('cloverAccount.has_private_token')
                ->where('cloverAccount.has_private_token', true)
                ->missing('cloverAccount.private_token')
                ->missing('cloverAccount.webhook_secret')
                ->missing('cloverAccount.webhook_endpoint_url')
            );
    }

    public function test_admin_can_deactivate_clover_account(): void
    {
        $account = CloverAccount::factory()->create(['is_active' => true]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.clover-accounts.deactivate', $account))
            ->assertRedirect(route('admin.clover-accounts.index'));

        $this->assertDatabaseHas('clover_accounts', [
            'id' => $account->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_activate_clover_account(): void
    {
        $account = CloverAccount::factory()->create(['is_active' => false]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.clover-accounts.activate', $account))
            ->assertRedirect(route('admin.clover-accounts.index'));

        $this->assertDatabaseHas('clover_accounts', [
            'id' => $account->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_delete_clover_account_with_no_payments(): void
    {
        $account = CloverAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->delete(route('admin.clover-accounts.destroy', $account))
            ->assertRedirect(route('admin.clover-accounts.index'));

        $this->assertDatabaseMissing('clover_accounts', ['id' => $account->id]);
    }

    public function test_sandbox_environment_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->actingAs($this->adminUser())
            ->withSession(['_token' => 'test_token'])
            ->post(route('admin.clover-accounts.store'), [
                'account_name' => 'Test Account',
                'merchant_id' => 'test-merchant-id',
                'api_access_key' => 'test-api-access-key',
                'private_token' => 'test-private-token',
                'environment' => 'sandbox',
                '_token' => 'test_token',
            ])
            ->assertSessionHasErrors(['private_token']);
    }

    public function test_non_admin_cannot_access_clover_accounts(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles(['agent']);

        $this->actingAs($user)
            ->get(route('admin.clover-accounts.index'))
            ->assertForbidden();
    }
}
