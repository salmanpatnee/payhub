<?php

use App\Http\Controllers\Api\Central\CentralSummaryController;
use Illuminate\Support\Facades\Route;

Route::prefix('central/v1')
    ->middleware(['central.enabled', 'throttle:central-ip', 'central.token'])
    ->group(function () {
        Route::get('summary', CentralSummaryController::class)->name('central.summary');
    });
