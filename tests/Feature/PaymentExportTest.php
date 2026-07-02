<?php

use App\Exports\PaymentsExport;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\RevolutAccount;
use App\Models\SquareAccount;
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

it('maps provider-aware account columns for stripe and revolut rows', function () {
    // The export must show the correct provider, account name, and provider
    // reference (Revolut order id / Stripe PaymentIntent id) per payment — the
    // old hardcoded "Stripe Account" column left Revolut rows blank.
    $headings = (new PaymentsExport(Payment::query()))->headings();
    expect($headings)->toContain('Provider', 'Payment Account', 'Provider Reference');
    expect($headings)->not->toContain('Stripe Account');

    $with = ['brand', 'stripeAccount', 'revolutAccount', 'squareAccount', 'relationshipManager'];
    $export = new PaymentsExport(Payment::query());

    $stripeAccount = StripeAccount::factory()->create(['account_name' => 'Acme Stripe']);
    $stripePayment = Payment::factory()
        ->for(Brand::factory()->create())
        ->for($stripeAccount, 'stripeAccount')
        ->create(['stripe_payment_intent_id' => 'pi_123']);

    $stripeRow = $export->map($stripePayment->load($with));
    expect($stripeRow[6])->toBe('Stripe');
    expect($stripeRow[7])->toBe('Acme Stripe');
    expect($stripeRow[8])->toBe('pi_123');

    $revolutAccount = RevolutAccount::factory()->create(['account_name' => 'Acme Revolut']);
    $revolutPayment = Payment::factory()->revolut()->create([
        'revolut_account_id' => $revolutAccount->id,
        'revolut_order_id' => 'ord_999',
    ]);

    $revolutRow = $export->map($revolutPayment->load($with));
    expect($revolutRow[6])->toBe('Revolut');
    expect($revolutRow[7])->toBe('Acme Revolut');
    expect($revolutRow[8])->toBe('ord_999');

    $squareAccount = SquareAccount::factory()->create(['account_name' => 'Acme Square']);
    $squarePayment = Payment::factory()->square()->create([
        'square_account_id' => $squareAccount->id,
        'square_payment_id' => 'sq_pay_777',
    ]);

    $squareRow = $export->map($squarePayment->load($with));
    expect($squareRow[6])->toBe('Square');
    expect($squareRow[7])->toBe('Acme Square');
    expect($squareRow[8])->toBe('sq_pay_777');
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
