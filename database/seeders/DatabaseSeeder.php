<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seed roles first — assignRole() requires these to exist
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'account', 'guard_name' => 'web']);

        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'PayHub Admin',
                'email' => 'admin@payhub.test',
                'password' => Hash::make('password'),
            ]
        );

        $admin->syncRoles(['admin']);

    }
}
