<?php

namespace App\Console\Commands;

use App\Models\MpgsChargeAttempt;
use App\Services\Mpgs\MpgsAttemptOutcomeLookup;
use Illuminate\Console\Command;

class BackfillMpgsAttemptReasons extends Command
{
    protected $signature = 'mpgs:backfill-attempt-reasons
        {--dry-run : Show what would be saved without writing anything}
        {--payment= : Only attempts of this payment id}';

    protected $description = 'Fill in the 3DS result, gateway code and reason on finished MPGS attempts that have none (read-only gateway lookups)';

    public function handle(MpgsAttemptOutcomeLookup $lookup): int
    {
        $dry = (bool) $this->option('dry-run');

        $attempts = MpgsChargeAttempt::query()
            ->with('payment.mpgsAccount')
            ->whereIn('status', ['abandoned', 'hard_error', 'declined'])
            ->whereNull('decline_reason')
            ->when($this->option('payment'), fn ($query, $id) => $query->where('payment_id', $id))
            ->orderBy('id')
            ->get();

        $this->info(($dry ? 'Dry run: ' : '')."{$attempts->count()} attempt(s) without a reason.");

        $filled = 0;

        foreach ($attempts as $attempt) {
            $outcome = $lookup->check($attempt);

            if ($outcome === null || $outcome['reason'] === null) {
                $this->line("  {$attempt->order_id}: nothing at the gateway to save");

                continue;
            }

            $this->line("  {$attempt->order_id}: {$outcome['reason']}");
            $filled++;

            if (! $dry) {
                $attempt->update(array_filter([
                    'auth_status' => $attempt->auth_status ?? $outcome['auth_status'],
                    'gateway_code' => $attempt->gateway_code ?? $outcome['gateway_code'],
                    'decline_reason' => $outcome['reason'],
                    'last_checked_at' => now(),
                ], fn ($value) => $value !== null));
            }
        }

        $this->info(($dry ? 'Would save' : 'Saved')." {$filled} reason(s).");

        return self::SUCCESS;
    }
}
