<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
});

// PAY-01: Admin can create a payment with all required fields
it('admin can create a payment record with all fields', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// PAY-01: User (non-admin) can also create a payment
it('non-admin user can create a payment record', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// PAY-02: Only active Stripe accounts are accepted — inactive rejected with 422
it('rejects inactive stripe account with validation error', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// PAY-03: client_name and client_email are stored on the Payment record
it('stores client name and email on the payment record', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// PAY-04: UUID is generated and show page is accessible at /payments/{uuid}
it('generates a uuid and redirects to show page after creation', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// PAY-05 + SEC-02: Amount stored as integer cents from decimal input; never re-read from request
it('converts decimal amount to integer cents and stores server-side', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// PAY-06: Only usd and gbp are accepted; other currencies rejected
it('accepts usd and gbp currencies and rejects others', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// PAY-07: expires_at is null after creation
it('sets expires_at to null on payment creation', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// Role-scoped index: Admin sees all payments, User sees only own
it('admin sees all payments on index and user sees only own', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});

// SEC-02: Controller store() must not accept amount from request body directly
it('amount in database matches round of submitted decimal times 100', function () {
    $this->markTestIncomplete('Fill in Wave 1');
});
