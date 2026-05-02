<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\StripeAccount;
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
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

        $brand = Brand::firstOrCreate(
            ['slug' => 'demo-brand'],
            [
                'name'            => 'Demo Brand',
                'logo_path'       => null,
                'primary_color'   => '#3B82F6',
                'secondary_color' => '#EFF6FF',
            ]
        );

        StripeAccount::firstOrCreate(
            ['account_name' => 'Demo Stripe Account'],
            [
                'brand_id'        => $brand->id,
                'publishable_key' => 'pk_test_placeholder_for_dev_only',
                'secret_key'      => 'sk_test_placeholder_for_dev_only',
                'webhook_secret'  => 'whsec_placeholder_for_dev_only',
                'is_active'       => true,
            ]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@payhub.test'],
            [
                'name'     => 'PayHub Admin',
                'password' => Hash::make('password'),
            ]
        );

        $admin->syncRoles(['admin']);
    }
}
