<?php

namespace Tests\Feature\Admin;

use App\Models\SquareAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SquareAccountManagementTest extends TestCase
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

    public function test_admin_can_view_square_accounts(): void
    {
        SquareAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.square-accounts.index'))
            ->assertOk();
    }

    public function test_admin_can_create_square_account(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.square-accounts.store'), [
                'account_name' => 'Test Square Account',
                'application_id' => 'sandbox-sq0idb-test123',
                'location_id' => 'LTEST123',
                'environment' => 'sandbox',
                'access_token' => 'EAAA_test_token',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.square-accounts.index'));

        $this->assertDatabaseHas('square_accounts', [
            'account_name' => 'Test Square Account',
            'environment' => 'sandbox',
        ]);
    }

    public function test_admin_can_update_square_account_without_changing_token(): void
    {
        $account = SquareAccount::factory()->create(['account_name' => 'Old Name']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.square-accounts.update', $account), [
                'account_name' => 'New Name',
                'application_id' => $account->application_id,
                'location_id' => $account->location_id,
                'environment' => $account->environment,
                'access_token' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.square-accounts.index'));

        $this->assertDatabaseHas('square_accounts', [
            'id' => $account->id,
            'account_name' => 'New Name',
        ]);
    }

    public function test_blank_access_token_on_update_preserves_existing(): void
    {
        $account = SquareAccount::factory()->create();
        $account->access_token = 'EAAA_original_token';
        $account->save();

        $this->actingAs($this->adminUser())
            ->put(route('admin.square-accounts.update', $account), [
                'account_name' => $account->account_name,
                'application_id' => $account->application_id,
                'location_id' => $account->location_id,
                'environment' => $account->environment,
                'access_token' => '',
            ])
            ->assertSessionHasNoErrors();

        $account->refresh();
        expect($account->access_token)->toBe('EAAA_original_token');
    }

    public function test_admin_can_update_square_account_with_new_token(): void
    {
        $account = SquareAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->put(route('admin.square-accounts.update', $account), [
                'account_name' => $account->account_name,
                'application_id' => 'sandbox-sq0idb-newid',
                'location_id' => 'LNEW123',
                'environment' => 'sandbox',
                'access_token' => 'EAAA_new_token',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.square-accounts.index'));

        $account->refresh();
        expect($account->access_token)->toBe('EAAA_new_token');
    }

    public function test_edit_returns_has_webhook_signature_key_bool_never_raw_key(): void
    {
        $account = SquareAccount::factory()->create();
        $account->webhook_signature_key = 'sq_sig_secret_value';
        $account->save();

        $this->actingAs($this->adminUser())
            ->get(route('admin.square-accounts.edit', $account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('squareAccount.has_webhook_signature_key')
                ->where('squareAccount.has_webhook_signature_key', true)
                ->has('squareAccount.webhook_endpoint_url')
                ->missing('squareAccount.access_token')
                ->missing('squareAccount.webhook_signature_key')
            );
    }

    public function test_admin_can_deactivate_square_account(): void
    {
        $account = SquareAccount::factory()->create(['is_active' => true]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.square-accounts.deactivate', $account))
            ->assertRedirect(route('admin.square-accounts.index'));

        $this->assertDatabaseHas('square_accounts', [
            'id' => $account->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_delete_square_account_with_no_payments(): void
    {
        $account = SquareAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->delete(route('admin.square-accounts.destroy', $account))
            ->assertRedirect(route('admin.square-accounts.index'));

        $this->assertDatabaseMissing('square_accounts', ['id' => $account->id]);
    }

    public function test_sandbox_environment_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->actingAs($this->adminUser())
            ->withSession(['_token' => 'test_token'])
            ->post(route('admin.square-accounts.store'), [
                'account_name' => 'Test Account',
                'application_id' => 'sandbox-sq0idb-test',
                'location_id' => 'LTEST',
                'environment' => 'sandbox',
                'access_token' => 'EAAA_test',
                '_token' => 'test_token',
            ])
            ->assertSessionHasErrors(['access_token']);
    }

    public function test_non_admin_cannot_access_square_accounts(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles(['agent']);

        $this->actingAs($user)
            ->get(route('admin.square-accounts.index'))
            ->assertForbidden();
    }
}
