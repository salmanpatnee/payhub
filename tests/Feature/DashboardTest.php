<?php

use App\Models\User;
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

it('redirects guests to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('lets admins view the dashboard', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
});

it('lets account users view the dashboard', function () {
    $account = User::factory()->create();
    $account->syncRoles(['account']);

    $this->actingAs($account)->get(route('dashboard'))->assertOk();
});

it('forbids agents from the dashboard', function () {
    $agent = User::factory()->create();
    $agent->syncRoles(['agent']);

    $this->actingAs($agent)->get(route('dashboard'))->assertForbidden();
});
