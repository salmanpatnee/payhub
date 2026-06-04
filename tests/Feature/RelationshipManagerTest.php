<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RelationshipManagerTest extends TestCase
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

    private function agentUser(): User
    {
        $agent = User::factory()->create(['email_verified_at' => now()]);
        $agent->syncRoles(['agent']);

        return $agent;
    }

    public function test_admin_can_view_rm_index(): void
    {
        RelationshipManager::create(['name' => 'Alice']);

        $this->actingAs($this->adminUser())
            ->get(route('admin.relationship-managers.index'))
            ->assertOk();
    }

    public function test_admin_can_create_rm(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.relationship-managers.store'), ['name' => 'Bob'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.relationship-managers.index'));

        $this->assertDatabaseHas('relationship_managers', ['name' => 'Bob']);
    }

    public function test_name_must_be_unique_on_create(): void
    {
        RelationshipManager::create(['name' => 'Charlie']);

        $this->actingAs($this->adminUser())
            ->post(route('admin.relationship-managers.store'), ['name' => 'Charlie'])
            ->assertSessionHasErrors(['name']);
    }

    public function test_admin_can_update_rm(): void
    {
        $rm = RelationshipManager::create(['name' => 'Dave']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.relationship-managers.update', $rm), ['name' => 'David'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.relationship-managers.index'));

        $this->assertDatabaseHas('relationship_managers', ['id' => $rm->id, 'name' => 'David']);
    }

    public function test_update_allows_keeping_own_name(): void
    {
        $rm = RelationshipManager::create(['name' => 'Eve']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.relationship-managers.update', $rm), ['name' => 'Eve'])
            ->assertSessionHasNoErrors();
    }

    public function test_admin_can_delete_rm_with_no_payments(): void
    {
        $rm = RelationshipManager::create(['name' => 'Frank']);

        $this->actingAs($this->adminUser())
            ->delete(route('admin.relationship-managers.destroy', $rm))
            ->assertRedirect(route('admin.relationship-managers.index'));

        $this->assertDatabaseMissing('relationship_managers', ['id' => $rm->id]);
    }

    public function test_admin_cannot_delete_rm_with_payments(): void
    {
        $rm = RelationshipManager::create(['name' => 'Grace']);
        Payment::factory()->create(['relationship_manager_id' => $rm->id]);

        $this->actingAs($this->adminUser())
            ->delete(route('admin.relationship-managers.destroy', $rm))
            ->assertRedirect(route('admin.relationship-managers.index'));

        $this->assertDatabaseHas('relationship_managers', ['id' => $rm->id]);
    }

    public function test_stored_rm_defaults_to_active(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.relationship-managers.store'), ['name' => 'Heidi'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('relationship_managers', ['name' => 'Heidi', 'is_active' => true]);
    }

    public function test_admin_can_deactivate_rm(): void
    {
        $rm = RelationshipManager::factory()->create();

        $this->actingAs($this->adminUser())
            ->patch(route('admin.relationship-managers.deactivate', $rm))
            ->assertRedirect(route('admin.relationship-managers.index'));

        $this->assertDatabaseHas('relationship_managers', ['id' => $rm->id, 'is_active' => false]);
    }

    public function test_admin_can_activate_rm(): void
    {
        $rm = RelationshipManager::factory()->inactive()->create();

        $this->actingAs($this->adminUser())
            ->patch(route('admin.relationship-managers.activate', $rm))
            ->assertRedirect(route('admin.relationship-managers.index'));

        $this->assertDatabaseHas('relationship_managers', ['id' => $rm->id, 'is_active' => true]);
    }

    public function test_admin_can_toggle_is_active_on_update(): void
    {
        $rm = RelationshipManager::factory()->create();

        $this->actingAs($this->adminUser())
            ->put(route('admin.relationship-managers.update', $rm), ['name' => $rm->name, 'is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('relationship_managers', ['id' => $rm->id, 'is_active' => false]);
    }

    public function test_agent_cannot_access_rm_routes(): void
    {
        $this->actingAs($this->agentUser())
            ->get(route('admin.relationship-managers.index'))
            ->assertForbidden();
    }

    public function test_guest_cannot_access_rm_routes(): void
    {
        $this->get(route('admin.relationship-managers.index'))
            ->assertRedirect(route('login'));
    }
}
