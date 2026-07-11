<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\VivaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VivaAccountManagementTest extends TestCase
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

    public function test_admin_can_view_viva_accounts(): void
    {
        VivaAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.viva-accounts.index'))
            ->assertOk();
    }

    public function test_admin_can_create_viva_account(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.viva-accounts.store'), [
                'account_name' => 'Test Viva Account',
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
                'merchant_id' => 'test-merchant-id',
                'api_key' => 'test-api-key',
                'source_code' => '1234',
                'environment' => 'demo',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.viva-accounts.index'));

        $this->assertDatabaseHas('viva_accounts', [
            'account_name' => 'Test Viva Account',
            'environment' => 'demo',
        ]);
    }

    public function test_admin_can_update_viva_account_without_changing_secrets(): void
    {
        $account = VivaAccount::factory()->create(['account_name' => 'Old Name']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.viva-accounts.update', $account), [
                'account_name' => 'New Name',
                'client_id' => $account->client_id,
                'merchant_id' => $account->merchant_id,
                'source_code' => $account->source_code,
                'environment' => $account->environment,
                'client_secret' => '',
                'api_key' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.viva-accounts.index'));

        $this->assertDatabaseHas('viva_accounts', [
            'id' => $account->id,
            'account_name' => 'New Name',
        ]);
    }

    public function test_blank_secrets_on_update_preserve_existing(): void
    {
        $account = VivaAccount::factory()->create();
        $account->client_secret = 'original_client_secret';
        $account->api_key = 'original_api_key';
        $account->save();

        $this->actingAs($this->adminUser())
            ->put(route('admin.viva-accounts.update', $account), [
                'account_name' => $account->account_name,
                'client_id' => $account->client_id,
                'merchant_id' => $account->merchant_id,
                'source_code' => $account->source_code,
                'environment' => $account->environment,
                'client_secret' => '',
                'api_key' => '',
            ])
            ->assertSessionHasNoErrors();

        $account->refresh();
        expect($account->client_secret)->toBe('original_client_secret');
        expect($account->api_key)->toBe('original_api_key');
    }

    public function test_admin_can_update_viva_account_with_new_secrets(): void
    {
        $account = VivaAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->put(route('admin.viva-accounts.update', $account), [
                'account_name' => $account->account_name,
                'client_id' => 'new-client-id',
                'merchant_id' => $account->merchant_id,
                'source_code' => $account->source_code,
                'environment' => $account->environment,
                'client_secret' => 'new_client_secret',
                'api_key' => 'new_api_key',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.viva-accounts.index'));

        $account->refresh();
        expect($account->client_id)->toBe('new-client-id');
        expect($account->client_secret)->toBe('new_client_secret');
        expect($account->api_key)->toBe('new_api_key');
    }

    public function test_edit_returns_has_secret_bools_never_raw_secrets(): void
    {
        $account = VivaAccount::factory()->create();
        $account->client_secret = 'client_secret_value';
        $account->api_key = 'api_key_value';
        $account->webhook_verification_key = 'webhook_verification_key_value';
        $account->save();

        $this->actingAs($this->adminUser())
            ->get(route('admin.viva-accounts.edit', $account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vivaAccount.has_client_secret')
                ->where('vivaAccount.has_client_secret', true)
                ->has('vivaAccount.has_api_key')
                ->where('vivaAccount.has_api_key', true)
                ->has('vivaAccount.has_webhook_verification_key')
                ->where('vivaAccount.has_webhook_verification_key', true)
                ->has('vivaAccount.webhook_endpoint_url')
                ->has('vivaAccount.webhook_verify_url')
                ->missing('vivaAccount.client_secret')
                ->missing('vivaAccount.api_key')
                ->missing('vivaAccount.webhook_verification_key')
            );
    }

    public function test_admin_can_deactivate_viva_account(): void
    {
        $account = VivaAccount::factory()->create(['is_active' => true]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.viva-accounts.deactivate', $account))
            ->assertRedirect(route('admin.viva-accounts.index'));

        $this->assertDatabaseHas('viva_accounts', [
            'id' => $account->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_activate_viva_account(): void
    {
        $account = VivaAccount::factory()->create(['is_active' => false]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.viva-accounts.activate', $account))
            ->assertRedirect(route('admin.viva-accounts.index'));

        $this->assertDatabaseHas('viva_accounts', [
            'id' => $account->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_delete_viva_account_with_no_payments(): void
    {
        $account = VivaAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->delete(route('admin.viva-accounts.destroy', $account))
            ->assertRedirect(route('admin.viva-accounts.index'));

        $this->assertDatabaseMissing('viva_accounts', ['id' => $account->id]);
    }

    public function test_demo_environment_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->actingAs($this->adminUser())
            ->withSession(['_token' => 'test_token'])
            ->post(route('admin.viva-accounts.store'), [
                'account_name' => 'Test Account',
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
                'merchant_id' => 'test-merchant-id',
                'api_key' => 'test-api-key',
                'source_code' => '1234',
                'environment' => 'demo',
                '_token' => 'test_token',
            ])
            ->assertSessionHasErrors(['client_secret']);
    }

    public function test_non_admin_cannot_access_viva_accounts(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles(['agent']);

        $this->actingAs($user)
            ->get(route('admin.viva-accounts.index'))
            ->assertForbidden();
    }
}
