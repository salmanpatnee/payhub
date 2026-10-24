<?php

namespace App\Jobs;

use App\Models\MpgsChargeAttempt;
use App\Services\Mpgs\MpgsAttemptOutcomeLookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Saves why an MPGS attempt ended the way it did. Read only against the gateway:
 * it retrieves the order and writes the 3DS result, gateway code and a plain
 * reason onto the attempt. It never changes the attempt's status or the payment,
 * and never overwrites a value that is already there.
 *
 * Needed because a bank 3DS refusal happens in the payer's browser and PAY is
 * never called, so the server would otherwise only ever see "abandoned".
 */
class RecordMpgsAttemptOutcome implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $attemptId,
    ) {}

    public function handle(MpgsAttemptOutcomeLookup $lookup): void
    {
        $attempt = MpgsChargeAttempt::with('payment.mpgsAccount')->find($this->attemptId);

        if (! $attempt) {
            return;
        }

        $outcome = $lookup->check($attempt);

        if ($outcome === null) {
            return;
        }

        $attempt->update(array_filter([
            'auth_status' => $attempt->auth_status ?? $outcome['auth_status'],
            'gateway_code' => $attempt->gateway_code ?? $outcome['gateway_code'],
            'decline_reason' => $attempt->decline_reason ?? $outcome['reason'],
            'last_checked_at' => now(),
        ], fn ($value) => $value !== null));
    }
}
