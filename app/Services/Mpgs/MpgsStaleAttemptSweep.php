<?php

namespace App\Services\Mpgs;

use App\Jobs\RecordMpgsAttemptOutcome;
use App\Jobs\ResolveMpgsChargeAttempt;
use App\Models\MpgsChargeAttempt;

/**
 * The scheduled backstop for MPGS attempts (spec 0005, AC-10), run every 10
 * minutes from routes/console.php. It never calls the gateway itself:
 *
 * - `pending` attempts that have gone stale are re-dispatched to the read only
 *   resolver job. "Stale" means not checked in the last 10 minutes and not
 *   touched in the last 2, so a PAY that is still in flight on the pay path is
 *   left alone.
 * - `prepared` attempts older than the configured session lifetime are
 *   expired to `abandoned` (the client never paid) and their encrypted
 *   session id is cleared. The payment is untouched.
 */
class MpgsStaleAttemptSweep
{
    public function __invoke(): void
    {
        $this->redispatchPending();
        $this->expirePrepared();
    }

    private function redispatchPending(): void
    {
        MpgsChargeAttempt::query()
            ->where('status', 'pending')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->where(function ($query) {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', now()->subMinutes(10));
            })
            ->each(fn (MpgsChargeAttempt $attempt) => ResolveMpgsChargeAttempt::dispatch($attempt->id));
    }

    private function expirePrepared(): void
    {
        $lifetime = (int) config('services.mpgs.session_lifetime_minutes');

        MpgsChargeAttempt::query()
            ->where('status', 'prepared')
            ->where('created_at', '<', now()->subMinutes($lifetime))
            ->each(function (MpgsChargeAttempt $attempt) {
                $attempt->update([
                    'status' => 'abandoned',
                    'session_id' => null,
                ]);
                RecordMpgsAttemptOutcome::dispatch($attempt->id);
            });
    }
}
