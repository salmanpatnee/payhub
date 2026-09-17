<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backstop for the rare case a Clover webhook never arrives — see
// ReconcileCloverPayments' docstring.
Schedule::command('clover:reconcile-payments')->everyFifteenMinutes();
