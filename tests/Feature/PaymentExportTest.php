<?php

use App\Exports\PaymentsExport;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'account', 'guard_name' => 'web']);
});

function makePayment(array $attributes = []): Payment
{
    return Payment::factory()
        ->for(Brand::factory()->create())
        ->for(StripeAccount::factory()->create(), 'stripeAccount')
        ->create($attributes);
}

it('lets an admin export the payments register', function () {
    Excel::fake();
    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('payments.export'))
        ->assertOk();

    Excel::assertDownloaded('payments-'.now()->format('Y-m-d').'.xlsx');
});

it('lets an account user export the payments register', function () {
    Excel::fake();
    $account = User::factory()->create()->assignRole('account');

    $this->actingAs($account)
        ->get(route('payments.export'))
        ->assertOk();

    Excel::assertDownloaded('payments-'.now()->format('Y-m-d').'.xlsx');
});

it('forbids agents from exporting', function () {
    $agent = User::factory()->create()->assignRole('agent');

    $this->actingAs($agent)
        ->get(route('payments.export'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('payments.export'))
        ->assertRedirect(route('login'));
});

it('scopes the export to the active brand filter', function () {
    Excel::fake();
    $admin = User::factory()->create()->assignRole('admin');

    $brandA = Brand::factory()->create();
    $brandB = Brand::factory()->create();
    makePayment(['brand_id' => $brandA->id]);
    makePayment(['brand_id' => $brandA->id]);
    makePayment(['brand_id' => $brandB->id]);

    $this->actingAs($admin)
        ->get(route('payments.export', ['brand_id' => $brandA->id]))
        ->assertOk();

    Excel::assertDownloaded(
        'payments-'.now()->format('Y-m-d').'.xlsx',
        fn (PaymentsExport $export) => $export->query()->count() === 2,
    );
});
