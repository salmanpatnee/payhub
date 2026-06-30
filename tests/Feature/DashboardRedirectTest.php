<?php

use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'account', 'guard_name' => 'web']);
});

it('sends admins to the dashboard from home', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)->get('/')->assertRedirect('/dashboard');
});

it('sends account users to the dashboard from home', function () {
    $account = User::factory()->create();
    $account->syncRoles(['account']);

    $this->actingAs($account)->get('/')->assertRedirect('/dashboard');
});

it('sends agents to payments from home', function () {
    $agent = User::factory()->create();
    $agent->syncRoles(['agent']);

    $this->actingAs($agent)->get('/')->assertRedirect('/payments');
});

it('resolves the home path per role', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);
    $agent = User::factory()->create();
    $agent->syncRoles(['agent']);

    expect(Navigation::homePathFor($admin))->toBe('/dashboard');
    expect(Navigation::homePathFor($agent))->toBe('/payments');
    expect(Navigation::homePathFor(null))->toBe('/payments');
});
