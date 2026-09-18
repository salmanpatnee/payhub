<?php

namespace App\Jobs;

use App\Models\CloverChargeAttempt;
use App\Services\Clover\CloverChargeClassifier;
use App\Services\Clover\CloverChargeResolver;
use App\Services\Clover\CloverClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a Clover charge attempt PayHub's server couldn't classify from the
 * synchronous /v1/charges response (a timeout, a 5xx, or an unrecognized body).
 * Never re-submits the charge — spec 0002's 2026-09-17 cross check found a
 * token replay both unsafe (Hosted Iframe tokens are single-use) and
 * impossible (PayHub never stores raw card data to retry with). Reads
 * Clover's own record of the charge instead: by clover_charge_id once known,
 * else a read-only search by the attempt's idempotency_key.
 *
 * Dispatched immediately (with a short delay) after an unknown classification,
 * and re-dispatched by the scheduled sweep (routes/console.php) as a backstop
 * for an attempt orphaned by a dead queue worker.
 */
class ResolveCloverChargeAttempt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(
        public readonly int $attemptId,
    ) {}

    public function handle(): void
    {
        $attempt = CloverChargeAttempt::with('payment.cloverAccount')->find($this->attemptId);

        if (! $attempt || $attempt->status !== 'pending') {
            return; // already resolved by the synchronous handler, or a prior run of this job
        }

        $payment = $attempt->payment;
        $cloverAccount = $payment?->cloverAccount;

        if (! $payment || ! $cloverAccount) {
            return;
        }

        $clover = app()->make(CloverClient::class, [
            'merchantId' => $cloverAccount->merchant_id,
            'privateToken' => $cloverAccount->private_token,
            'environment' => $cloverAccount->environment,
        ]);

        if ($attempt->clover_charge_id) {
            $result = $clover->getCharge($attempt->clover_charge_id);
            $status = $result['status'];
            $body = $result['body'];
        } else {
            $charge = $clover->findChargeByReference($attempt->idempotency_key);
            $status = $charge ? 200 : 404;
            $body = $charge ?? [];
        }

        if ($body === []) {
            $this->bumpAndRetry($attempt, 'Clover charge attempt '.$attempt->id.' could not be resolved yet (no matching charge found).');

            return;
        }

        $classification = CloverChargeClassifier::classify($status, $body, $payment->amount, $payment->currency);

        if ($classification === 'unknown') {
            $this->bumpAndRetry($attempt, 'Clover charge attempt '.$attempt->id.' is still unresolved.');

            return;
        }

        app(CloverChargeResolver::class)->resolve($attempt, $classification, $body);
    }

    /**
     * Records that a resolution check happened, then throws so Laravel's own
     * $tries/backoff retries the job — failed() below takes over once retries
     * are exhausted.
     */
    private function bumpAndRetry(CloverChargeAttempt $attempt, string $message): void
    {
        $attempt->increment('attempts_count');
        $attempt->update(['last_checked_at' => now()]);

        throw new \RuntimeException($message);
    }

    public function failed(?Throwable $exception): void
    {
        $attempt = CloverChargeAttempt::find($this->attemptId);

        if ($attempt && $attempt->status === 'pending') {
            $attempt->update(['status' => 'abandoned']);
        }

        Log::error('ResolveCloverChargeAttempt exhausted its retries — attempt abandoned, payment stays pending', [
            'attempt_id' => $this->attemptId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
