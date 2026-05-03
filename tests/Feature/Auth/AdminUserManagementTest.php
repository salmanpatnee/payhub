<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
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

    public function test_admin_can_view_user_list(): void
    {
        $this->actingAs($this->adminUser())
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_admin_can_create_user(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name'     => 'Test User',
                'email'    => 'testuser@example.com',
                'password' => 'Password1!',
                'role'     => 'user',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['email' => 'testuser@example.com']);
    }

    public function test_admin_can_update_user_role(): void
    {
        $admin  = $this->adminUser();
        $target = User::factory()->create(['email_verified_at' => now()]);
        $target->syncRoles(['user']);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $target), [
                'name'  => $target->name,
                'email' => $target->email,
                'role'  => 'admin',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($target->fresh()->hasRole('admin'));
    }

    public function test_admin_can_delete_user(): void
    {
        $admin  = $this->adminUser();
        $target = User::factory()->create(['email_verified_at' => now()]);
        $target->syncRoles(['user']);

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
