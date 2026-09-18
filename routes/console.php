<?php

use App\Jobs\ResolveCloverChargeAttempt;
use App\Models\CloverChargeAttempt;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backstop for a Clover charge attempt orphaned by a dead queue worker — the
// immediate dispatch from chargeClover() covers the normal case; this sweep
// only re-dispatches ones that have gone stale (spec 0002, Build plan step 9).
Schedule::call(function () {
    CloverChargeAttempt::query()
        ->where('status', 'pending')
        ->where(function ($query) {
            $query->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', now()->subMinutes(10));
        })
        ->each(fn (CloverChargeAttempt $attempt) => ResolveCloverChargeAttempt::dispatch($attempt->id));
})->name('clover:resolve-stale-charge-attempts')->everyTenMinutes()->withoutOverlapping();
