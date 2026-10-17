<?php

namespace App\Jobs;

use App\Models\MpgsChargeAttempt;
use App\Services\Mpgs\MpgsChargeClassifier;
use App\Services\Mpgs\MpgsChargeResolver;
use App\Services\Mpgs\MpgsClient;
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

        $mpgs = app()->make(MpgsClient::class, [
            'merchantId' => $account->merchant_id,
            'integrationPassword' => $account->integration_password,
            'apiVersion' => $account->api_version,
        ]);

        $result = $mpgs->retrieveOrder($attempt->order_id);

        // No such order yet (404) or an unusable body: the PAY outcome is not
        // known, so check again later.
        $entry = $result['body'] === [] ? null : MpgsChargeClassifier::findTransaction($result['body'], $attempt->transaction_id);

        if ($entry === null) {
            $this->bumpAndRetry($attempt, 'MPGS attempt '.$attempt->id.' could not be resolved yet (no matching PAY transaction found).');

            return;
        }

        $classification = MpgsChargeClassifier::fromOrder($result['body'], $attempt->transaction_id, $payment->amount, $payment->currency);

        if ($classification === 'unknown') {
            $this->bumpAndRetry($attempt, 'MPGS attempt '.$attempt->id.' is still unresolved.');

            return;
        }

        app(MpgsChargeResolver::class)->resolve($attempt, $classification, $entry);
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
        }

        Log::error('ResolveMpgsChargeAttempt exhausted its retries: attempt abandoned, payment stays pending', [
            'attempt_id' => $this->attemptId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
