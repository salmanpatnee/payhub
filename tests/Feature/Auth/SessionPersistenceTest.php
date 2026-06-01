<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SessionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
    }

    public function test_user_session_persists_with_remember_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('payments.index', absolute: false));

        $this->assertAuthenticated();
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_authenticated_user_can_reach_dashboard_across_requests(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
