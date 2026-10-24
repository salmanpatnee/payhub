<?php

use App\Jobs\RecordMpgsAttemptOutcome;
use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use App\Services\Mpgs\MpgsAttemptOutcomeLookup;
use App\Services\Mpgs\MpgsStaleAttemptSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function mpgsAbandonedAttempt(array $attributes = []): MpgsChargeAttempt
{
    $payment = Payment::factory()->mpgs()->create(['status' => 'pending', 'amount' => 1500, 'currency' => 'usd']);

    return MpgsChargeAttempt::factory()->create(array_merge([
        'payment_id' => $payment->id,
        'status' => 'abandoned',
        'transaction_id' => 'txn-pay',
    ], $attributes));
}

function mpgs3dsRefusedOrder(): array
{
    return [
        'status' => 'AUTHENTICATION_UNSUCCESSFUL',
        'authenticationStatus' => 'AUTHENTICATION_FAILED',
        'transaction' => [[
            'result' => 'FAILURE',
            'response' => ['gatewayCode' => 'DECLINED'],
            'transaction' => ['type' => 'AUTHENTICATION', 'id' => 'auth-1'],
            'authentication' => ['3ds2' => ['transactionStatus' => 'N', 'statusReasonCode' => '01']],
        ]],
    ];
}

it('saves the 3DS result and reason on an abandoned attempt without changing any status', function () {
    $attempt = mpgsAbandonedAttempt();
    Http::fake(['*/order/*' => Http::response(mpgs3dsRefusedOrder(), 200)]);

    (new RecordMpgsAttemptOutcome($attempt->id))->handle(app(MpgsAttemptOutcomeLookup::class));

    $attempt->refresh();
    expect($attempt->status)->toBe('abandoned');
    expect($attempt->auth_status)->toBe('AUTHENTICATION_FAILED');
    expect($attempt->gateway_code)->toBe('DECLINED');
    expect($attempt->decline_reason)->toBe('Bank refused the 3-D Secure check');
    expect($attempt->payment->fresh()->status)->toBe('pending');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

it('does nothing when the gateway has no such order', function () {
    $attempt = mpgsAbandonedAttempt();
    Http::fake(['*/order/*' => Http::response([], 404)]);

    (new RecordMpgsAttemptOutcome($attempt->id))->handle(app(MpgsAttemptOutcomeLookup::class));

    expect($attempt->fresh()->decline_reason)->toBeNull();
    expect($attempt->fresh()->auth_status)->toBeNull();
});

it('never overwrites a reason that is already saved', function () {
    $attempt = mpgsAbandonedAttempt(['decline_reason' => 'Value missing (sourceOfFunds.provided.card.securityCode)', 'status' => 'hard_error']);
    Http::fake(['*/order/*' => Http::response(mpgs3dsRefusedOrder(), 200)]);

    (new RecordMpgsAttemptOutcome($attempt->id))->handle(app(MpgsAttemptOutcomeLookup::class));

    expect($attempt->fresh()->decline_reason)->toBe('Value missing (sourceOfFunds.provided.card.securityCode)');
});

it('survives a gateway that cannot be reached', function () {
    $attempt = mpgsAbandonedAttempt();
    Http::fake(['*/order/*' => fn () => throw new ConnectionException('timeout')]);

    (new RecordMpgsAttemptOutcome($attempt->id))->handle(app(MpgsAttemptOutcomeLookup::class));

    expect($attempt->fresh()->decline_reason)->toBeNull();
});

it('is queued when the sweep expires a prepared attempt', function () {
    Queue::fake([RecordMpgsAttemptOutcome::class]);
    config(['services.mpgs.session_lifetime_minutes' => 30]);
    $payment = Payment::factory()->mpgs()->create(['status' => 'pending']);
    $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id, 'created_at' => now()->subHour()]);

    (new MpgsStaleAttemptSweep)();

    expect($attempt->fresh()->status)->toBe('abandoned');
    Queue::assertPushed(RecordMpgsAttemptOutcome::class, fn ($job) => $job->attemptId === $attempt->id);
});

it('backfills empty reasons only, and writes nothing on a dry run', function () {
    $attempt = mpgsAbandonedAttempt();
    Http::fake(['*/order/*' => Http::response(mpgs3dsRefusedOrder(), 200)]);

    $this->artisan('mpgs:backfill-attempt-reasons', ['--dry-run' => true])->assertSuccessful();
    expect($attempt->fresh()->decline_reason)->toBeNull();

    $this->artisan('mpgs:backfill-attempt-reasons')->assertSuccessful();
    expect($attempt->fresh()->decline_reason)->toBe('Bank refused the 3-D Secure check');
    expect($attempt->fresh()->status)->toBe('abandoned');
});
