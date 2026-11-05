<?php

use App\Models\Payment;
use App\Services\Kurv\KurvChargeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('runs one more lookup when a callback arrives while another lookup is running', function () {
    $payment = Payment::factory()->kurv()->create([
        'amount' => 1234, 'currency' => 'usd', 'status' => 'pending',
        'kurv_request_id' => '1074096OO', 'kurv_charge_started_at' => now()->subMinutes(5),
    ]);

    $calls = 0;
    Http::fake(['*/payment-requests/*' => function () use (&$calls, $payment) {
        $calls++;

        if ($calls === 1) {
            // A callback lands mid lookup: it cannot get the lock, so it leaves a recheck note.
            expect(app(KurvChargeResolver::class)->resolve($payment, 'callback'))->toBeNull();
        }

        return Http::response(['transaction' => ['transaction_id' => '1074096OO', 'status' => 'pending']], 200);
    }, '*/payments*' => Http::response(['payments' => []], 200)]);

    app(KurvChargeResolver::class)->resolve($payment, 'poll');

    expect($calls)->toBe(2);
    expect(Cache::has('kurv:recheck:'.$payment->id))->toBeFalse();
});

it('never writes the API key to the log when Kurv fails', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);
    $payment = Payment::factory()->kurv()->create(['status' => 'pending']);
    $payment->kurvAccount->forceFill(['api_key' => 'kp_test_supersecretkeyvalue'])->save();
    $payment->consents()->create(['policy_versions' => ['terms' => '1'], 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'accepted_at' => now()]);

    $this->postJson("/pay/{$payment->uuid}/kurv/checkout")->assertStatus(502);

    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode($context), 'supersecretkeyvalue'))->once();
});

it('never sends the API key to the pay page', function () {
    $payment = Payment::factory()->kurv()->create(['status' => 'pending']);
    $payment->kurvAccount->forceFill(['api_key' => 'kp_test_supersecretkeyvalue'])->save();

    $html = $this->get("/pay/{$payment->uuid}")->assertOk()->getContent();

    expect($html)->not->toContain('supersecretkeyvalue');
});
