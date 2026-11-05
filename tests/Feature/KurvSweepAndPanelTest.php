<?php

use App\Models\KurvLookupAttempt;
use App\Models\Payment;
use App\Models\User;
use App\Services\Kurv\KurvStalePaymentSweep;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    foreach (['admin', 'agent', 'account'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function kurvSweepPayment(array $overrides = []): Payment
{
    return Payment::factory()->kurv()->create(array_merge([
        'amount' => 1234,
        'currency' => 'usd',
        'status' => 'pending',
        'kurv_request_id' => '1074096OO',
        'kurv_payment_url' => 'https://sandbox-pay.kurv.app/once_off/abc',
        'kurv_charge_started_at' => now()->subMinutes(30),
    ], $overrides));
}

function fakeKurvPending(): void
{
    Http::fake([
        '*/payment-requests/*' => Http::response(['transaction' => ['transaction_id' => '1074096OO', 'status' => 'pending']], 200),
        '*/payments*' => Http::response(['payments' => []], 200),
    ]);
}

// ---- Sweep (AC-10) ----

it('looks at only pending Kurv payments inside the age window that were not checked lately', function () {
    fakeKurvPending();
    $due = kurvSweepPayment();
    $neverChecked = kurvSweepPayment(['kurv_last_lookup_at' => null]);
    $tooNew = kurvSweepPayment(['kurv_charge_started_at' => now()->subSeconds(30)]);
    $tooOld = kurvSweepPayment(['kurv_charge_started_at' => now()->subHours(80)]);
    $recent = kurvSweepPayment(['kurv_last_lookup_at' => now()->subMinutes(1)]);
    $completed = kurvSweepPayment(['status' => 'completed', 'paid_at' => now()]);
    $closed = kurvSweepPayment(['kurv_link_closed_at' => now()]);
    $noRequest = kurvSweepPayment(['kurv_request_id' => null, 'kurv_payment_url' => null]);
    $due->update(['kurv_last_lookup_at' => now()->subMinutes(20)]);

    (new KurvStalePaymentSweep)();

    $looked = KurvLookupAttempt::pluck('payment_id')->unique()->sort()->values()->all();

    expect($looked)->toBe(collect([$due->id, $neverChecked->id])->sort()->values()->all());
    expect(KurvLookupAttempt::first()->source)->toBe('sweep');
});

it('makes only read calls and never creates a Kurv request', function () {
    fakeKurvPending();
    kurvSweepPayment();

    (new KurvStalePaymentSweep)();

    Http::assertSent(fn ($request) => $request->method() === 'GET');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('completes a payment on an inactive account and never looks for declines', function () {
    Http::fake(['*/payment-requests/*' => Http::response(['transaction' => [
        'transaction_id' => '1074096OO', 'status' => 'success', 'payment_id' => 'p1',
        'amount' => 12.34, 'currency' => 'USD', 'payment_type' => 'DB', 'payment_date' => '2026-11-05T09:30:00.000Z',
    ]], 200)]);
    $payment = kurvSweepPayment();
    $payment->kurvAccount->update(['is_active' => false]);

    (new KurvStalePaymentSweep)();

    expect($payment->fresh()->status)->toBe('completed');
});

it('logs one warning with the count of payments still pending past the window', function () {
    Log::spy();
    fakeKurvPending();
    kurvSweepPayment(['kurv_charge_started_at' => now()->subHours(100)]);
    kurvSweepPayment(['kurv_charge_started_at' => now()->subHours(90)]);

    (new KurvStalePaymentSweep)();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['count'] ?? null) === 2)->once();
});

it('keeps going when one payment fails', function () {
    Http::fake(['*' => Http::response([], 500)]);
    kurvSweepPayment();
    kurvSweepPayment();

    (new KurvStalePaymentSweep)();

    expect(KurvLookupAttempt::where('outcome', 'error')->count())->toBe(2);
});

it('is scheduled every 10 minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'kurv:sweep-stale-payments');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

// ---- Attempts panel (AC-11) ----

it('shows admins the Kurv lookups newest first with a summary, and nobody else', function () {
    $payment = kurvSweepPayment(['kurv_payment_id' => 'p1']);
    KurvLookupAttempt::create(['payment_id' => $payment->id, 'source' => 'callback', 'outcome' => 'pending', 'kurv_status' => 'pending', 'kurv_request_id' => '1074096OO', 'created_at' => now()->subMinutes(5)]);
    KurvLookupAttempt::create(['payment_id' => $payment->id, 'source' => 'poll', 'outcome' => 'declined', 'kurv_status' => 'pending', 'result_code' => '253', 'reason' => 'Card Declined', 'kurv_request_id' => '1074096OO', 'created_at' => now()->subMinutes(1)]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get("/payments/{$payment->uuid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('attempts.provider', 'kurv')
            ->where('attempts.rows.0.status', 'declined')
            ->where('attempts.rows.0.status_detail', 'pending · 253')
            ->where('attempts.rows.0.reason', 'Card Declined')
            ->where('attempts.rows.0.source', 'poll')
            ->where('attempts.rows.0.reference', '1074096OO')
            ->where('attempts.rows.1.status', 'pending')
            ->where('attempts.summary.kurv_request_id', '1074096OO')
            ->where('attempts.summary.kurv_payment_id', 'p1')
            ->has('attempts.summary.charge_started_at'));

    $agent = User::factory()->create();
    $agent->assignRole('agent');
    $payment->update(['user_id' => $agent->id]);

    $this->actingAs($agent)->get("/payments/{$payment->uuid}")
        ->assertInertia(fn (Assert $page) => $page->where('attempts', null));
});

it('shows an empty list for a Kurv payment that has no lookups yet', function () {
    $payment = kurvSweepPayment(['kurv_request_id' => null, 'kurv_payment_url' => null, 'kurv_charge_started_at' => null]);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get("/payments/{$payment->uuid}")
        ->assertInertia(fn (Assert $page) => $page->where('attempts.provider', 'kurv')->where('attempts.rows', []));
});
