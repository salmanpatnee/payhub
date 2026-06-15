<?php

namespace Tests\Feature\Admin;

use App\Models\Payment;
use App\Models\RevolutAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RevolutAccountManagementTest extends TestCase
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

    public function test_admin_can_view_revolut_accounts(): void
    {
        RevolutAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.revolut-accounts.index'))
            ->assertOk();
    }

    public function test_admin_can_create_revolut_account(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.revolut-accounts.store'), [
                'account_name' => 'Test Account',
                'public_key' => 'pk_abc123',
                'secret_key' => 'sk_abc123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.revolut-accounts.index'));

        $this->assertDatabaseHas('revolut_accounts', ['account_name' => 'Test Account']);
    }

    public function test_secret_key_is_required_on_create(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.revolut-accounts.store'), [
                'account_name' => 'No Secret',
            ])
            ->assertSessionHasErrors('secret_key');
    }

    public function test_admin_can_update_account_without_changing_secret(): void
    {
        $account = RevolutAccount::factory()->create(['account_name' => 'Old Name']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.revolut-accounts.update', $account), [
                'account_name' => 'New Name',
                'secret_key' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.revolut-accounts.index'));

        $this->assertDatabaseHas('revolut_accounts', ['id' => $account->id, 'account_name' => 'New Name']);
    }

    public function test_webhook_secret_must_start_with_wsk_prefix(): void
    {
        $account = RevolutAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->put(route('admin.revolut-accounts.update', $account), [
                'account_name' => $account->account_name,
                'webhook_secret' => 'not_a_valid_secret',
            ])
            ->assertSessionHasErrors('webhook_secret');
    }

    public function test_edit_never_exposes_the_secret_or_webhook_secret(): void
    {
        $account = RevolutAccount::factory()->create();
        $account->webhook_secret = 'wsk_existing';
        $account->save();

        $this->actingAs($this->adminUser())
            ->get(route('admin.revolut-accounts.edit', $account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('revolutAccount.has_webhook_secret', true)
                ->missing('revolutAccount.secret_key')
                ->missing('revolutAccount.webhook_secret')
            );
    }

    public function test_admin_can_deactivate_and_activate_account(): void
    {
        $account = RevolutAccount::factory()->create(['is_active' => true]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.revolut-accounts.deactivate', $account))
            ->assertRedirect(route('admin.revolut-accounts.index'));
        $this->assertDatabaseHas('revolut_accounts', ['id' => $account->id, 'is_active' => false]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.revolut-accounts.activate', $account));
        $this->assertDatabaseHas('revolut_accounts', ['id' => $account->id, 'is_active' => true]);
    }

    public function test_admin_can_delete_account_with_no_payments(): void
    {
        $account = RevolutAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->delete(route('admin.revolut-accounts.destroy', $account))
            ->assertRedirect(route('admin.revolut-accounts.index'));

        $this->assertDatabaseMissing('revolut_accounts', ['id' => $account->id]);
    }

    public function test_account_with_payments_cannot_be_deleted(): void
    {
        $account = RevolutAccount::factory()->create();
        Payment::factory()->revolut()->create(['revolut_account_id' => $account->id]);

        $this->actingAs($this->adminUser())
            ->delete(route('admin.revolut-accounts.destroy', $account))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('revolut_accounts', ['id' => $account->id]);
    }

    public function test_non_admin_cannot_access_revolut_accounts(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles(['agent']);

        $this->actingAs($user)
            ->get(route('admin.revolut-accounts.index'))
            ->assertForbidden();
    }
}
