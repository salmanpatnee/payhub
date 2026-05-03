<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicPaymentRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
    }

    public function test_pay_route_is_reachable_without_authentication(): void
    {
        // Stub returns 404 (abort(404)) — not 302 redirect to login
        $this->get(route('pay.show', ['uuid' => 'test-uuid']))
            ->assertNotFound();
    }

    public function test_pay_route_does_not_redirect_guest_to_login(): void
    {
        $response = $this->get(route('pay.show', ['uuid' => 'test-uuid']));

        // assertNotRedirect does not exist in Laravel 13 — assert 404 (not a redirect)
        $response->assertStatus(404);
    }
}
