<?php

namespace Tests\Feature\Admin;

use App\Models\RelationshipManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RelationshipManagerManagementTest extends TestCase
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

    // Index returns a paginated structure
    public function test_index_returns_paginated_rms(): void
    {
        RelationshipManager::factory()->count(3)->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.relationship-managers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/relationship-managers/Index')
                ->has('rms.data', 3)
                ->where('rms.current_page', 1)
            );
    }

    // Search filters RMs by name
    public function test_search_filters_rms_by_name(): void
    {
        RelationshipManager::factory()->create(['name' => 'Alice Anderson']);
        RelationshipManager::factory()->create(['name' => 'Bob Brown']);

        $this->actingAs($this->adminUser())
            ->get(route('admin.relationship-managers.index', ['search' => 'Alice']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rms.data', 1)
                ->where('rms.data.0.name', 'Alice Anderson')
            );
    }

    // Pagination caps at 20 per page
    public function test_index_paginates_at_twenty_per_page(): void
    {
        RelationshipManager::factory()->count(25)->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.relationship-managers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rms.data', 20)
                ->where('rms.last_page', 2)
            );
    }
}
