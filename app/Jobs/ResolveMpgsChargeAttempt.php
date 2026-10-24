<?php

namespace App\Jobs;

use App\Models\MpgsChargeAttempt;
use App\Services\Mpgs\MpgsChargeResolver;
use App\Services\Mpgs\MpgsOrderLookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves an MPGS attempt PayHub's server couldn't classify from the PAY
 * response (a timeout, a 5xx, or an unrecognized body). Read only against the
 * gateway: it retrieves the order and picks the PAY transaction by the id
 * PayHub issued, never "the last one", and never re-sends PAY (spec 0005,
 * AC-10). The result goes through the shared MpgsChargeResolver, the one
 * place guarded status writes happen.
 *
 * Dispatched after an unknown classification, and re-dispatched by the
 * scheduled sweep (MpgsStaleAttemptSweep) for an attempt orphaned by a dead
 * queue worker. Once its retries are exhausted failed() marks the attempt
 * abandoned and leaves the payment alone.
 */
class ResolveMpgsChargeAttempt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(
        public readonly int $attemptId,
    ) {}

    public function handle(): void
    {
        $attempt = MpgsChargeAttempt::with('payment.mpgsAccount')->find($this->attemptId);

        if (! $attempt || $attempt->status !== 'pending') {
            return; // already resolved by the pay path, the webhook job, or a prior run of this job
        }

        $payment = $attempt->payment;
        $account = $payment?->mpgsAccount;

        if (! $payment || ! $account) {
            return;
        }

        // No such order yet (404) or no matching PAY transaction: the outcome is
        // not known, so check again later.
        $found = app(MpgsOrderLookup::class)->check($attempt);

        if ($found === null) {
            $this->bumpAndRetry($attempt, 'MPGS attempt '.$attempt->id.' could not be resolved yet (no matching PAY transaction found).');

            return;
        }

        if ($found['classification'] === 'unknown') {
            $this->bumpAndRetry($attempt, 'MPGS attempt '.$attempt->id.' is still unresolved.');

            return;
        }

        app(MpgsChargeResolver::class)->resolve($attempt, $found['classification'], $found['entry']);
    }

    /**
     * Records that a resolution check happened, then throws so Laravel's own
     * $tries/backoff retries the job. failed() below takes over once retries
     * are exhausted.
     */
    private function bumpAndRetry(MpgsChargeAttempt $attempt, string $message): void
    {
        $attempt->increment('attempts_count');
        $attempt->update(['last_checked_at' => now()]);

        throw new \RuntimeException($message);
    }

    public function failed(?Throwable $exception): void
    {
        $attempt = MpgsChargeAttempt::find($this->attemptId);

        if ($attempt && $attempt->status === 'pending') {
            $attempt->update(['status' => 'abandoned', 'session_id' => null]);
            RecordMpgsAttemptOutcome::dispatch($attempt->id);
        }

        Log::error('ResolveMpgsChargeAttempt exhausted its retries: attempt abandoned, payment stays pending', [
            'attempt_id' => $this->attemptId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
