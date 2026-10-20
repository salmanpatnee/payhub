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
 * Handles an MPGS notification (spec 0005, AC-9). The webhook is only a signal:
 * this job re-reads the order at the gateway and applies the lookup result
 * through the shared MpgsChargeResolver. Nothing in the notification body is
 * used for status, so a forged or misleading body changes nothing.
 *
 * If the gateway does not yet show a settled PAY, the job throws so Laravel's
 * $tries/backoff retries it. It does not abandon the attempt when retries run
 * out: that belongs to ResolveMpgsChargeAttempt and the scheduled sweep, which
 * keep watching a pending attempt.
 */
class HandleMpgsWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $attemptId,
    ) {}

    public function handle(): void
    {
        $attempt = MpgsChargeAttempt::with('payment.mpgsAccount')->find($this->attemptId);

        if (! $attempt || $attempt->status !== 'pending') {
            return; // already resolved by the pay path or the resolver job
        }

        $found = app(MpgsOrderLookup::class)->check($attempt);

        if ($found === null || $found['classification'] === 'unknown') {
            throw new \RuntimeException('MPGS attempt '.$attempt->id.' has no settled PAY at the gateway yet.');
        }

        app(MpgsChargeResolver::class)->resolve($attempt, $found['classification'], $found['entry']);
    }

    public function failed(?Throwable $exception): void
    {
        // Safe to leave the attempt pending: the resolver job and the sweep still own it.
        Log::warning('HandleMpgsWebhookJob exhausted its retries; leaving the attempt to the resolver', [
            'attempt_id' => $this->attemptId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
