<?php

namespace Tests\Feature\Auth;

use App\Models\Brand;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
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

    public function test_admin_can_view_user_list(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_admin_can_create_user(): void
    {
        $admin = $this->adminUser();

        $stripeAccount = StripeAccount::factory()->create(['is_active' => true]);
        $brand = Brand::factory()->create();
        $rm = RelationshipManager::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Test User',
                'username' => 'testuser',
                'password' => 'Password1!',
                'role' => 'agent',
                'provider' => 'stripe',
                'account_id' => $stripeAccount->id,
                'brand_ids' => [$brand->id],
                'relationship_manager_ids' => [$rm->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['username' => 'testuser']);

        $user = User::where('username', 'testuser')->firstOrFail();
        $this->assertDatabaseHas('brand_user', ['brand_id' => $brand->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('relationship_manager_user', ['relationship_manager_id' => $rm->id, 'user_id' => $user->id]);
    }

    public function test_create_excludes_inactive_rms_from_dropdown(): void
    {
        $active = RelationshipManager::factory()->create();
        $inactive = RelationshipManager::factory()->inactive()->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.users.create'))
            ->assertInertia(fn ($page) => $page
                ->has('relationshipManagers', 1)
                ->where('relationshipManagers.0.id', $active->id)
            );

        $this->assertDatabaseHas('relationship_managers', ['id' => $inactive->id, 'is_active' => false]);
    }

    public function test_edit_includes_assigned_inactive_rm(): void
    {
        $inactive = RelationshipManager::factory()->inactive()->create();
        $target = User::factory()->create(['email_verified_at' => now()]);
        $target->syncRoles(['agent']);
        $target->relationshipManagers()->sync([$inactive->id]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.users.edit', $target))
            ->assertInertia(fn ($page) => $page
                ->has('relationshipManagers', 1)
                ->where('relationshipManagers.0.id', $inactive->id)
            );
    }

    public function test_creating_agent_requires_at_least_one_brand_and_rm(): void
    {
        $admin = $this->adminUser();
        $stripeAccount = StripeAccount::factory()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'No Mappings',
                'username' => 'nomappings',
                'password' => 'Password1!',
                'role' => 'agent',
                'provider' => 'stripe',
                'account_id' => $stripeAccount->id,
            ])
            ->assertSessionHasErrors(['brand_ids', 'relationship_manager_ids']);

        $this->assertDatabaseMissing('users', ['username' => 'nomappings']);
    }

    public function test_admin_can_update_user_role(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create(['email_verified_at' => now()]);
        $target->syncRoles(['agent']);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $target), [
                'name' => $target->name,
                'username' => $target->username,
                'role' => 'admin',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($target->fresh()->hasRole('admin'));
    }

    public function test_admin_can_delete_user(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create(['email_verified_at' => now()]);
        $target->syncRoles(['agent']);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $target))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
