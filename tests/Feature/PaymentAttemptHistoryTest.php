<?php

use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

it('sends the MPGS attempt list to admins, newest attempt first', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $payment = Payment::factory()->mpgs()->create();
    MpgsChargeAttempt::factory()->create(['payment_id' => $payment->id, 'sequence' => 1, 'order_id' => "{$payment->id}-1", 'status' => 'abandoned']);
    MpgsChargeAttempt::factory()->create([
        'payment_id' => $payment->id,
        'sequence' => 2,
        'order_id' => "{$payment->id}-2",
        'status' => 'declined',
        'decline_reason' => 'Bank refused the 3-D Secure check',
    ]);

    $this->actingAs($admin)
        ->get(route('payments.show', $payment))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('attempts.provider', 'mpgs')
            ->has('attempts.rows', 2)
            ->where('attempts.rows.0.sequence', 2)
            ->where('attempts.rows.0.status', 'declined')
            ->where('attempts.rows.0.reason', 'Bank refused the 3-D Secure check')
            ->where('attempts.rows.1.reason', null)
            ->missing('attempts.rows.0.session_id')
        );
});

it('sends only the first and latest attempt summary for Authorize.net', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $payment = Payment::factory()->authorizenet()->create([
        'status' => 'pending',
        'authorizenet_first_attempt_at' => now()->subMinutes(30),
        'authorizenet_last_attempt_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('payments.show', $payment))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('attempts.provider', 'authorizenet')
            ->where('attempts.summary.status', 'pending')
            ->where('attempts.summary.transaction_id', null)
            ->has('attempts.summary.first_attempt_at')
            ->has('attempts.summary.last_attempt_at')
        );
});

it('does not send attempt history to agents', function () {
    $agent = User::factory()->create()->assignRole('agent');
    $payment = Payment::factory()->mpgs()->create(['user_id' => $agent->id]);
    MpgsChargeAttempt::factory()->create(['payment_id' => $payment->id]);

    $this->actingAs($agent)
        ->get(route('payments.show', $payment))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('attempts', null));
});

it('sends no attempt history for providers without it', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $payment = Payment::factory()->create();

    $this->actingAs($admin)
        ->get(route('payments.show', $payment))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('attempts', null));
});
