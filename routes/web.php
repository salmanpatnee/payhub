<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('/admin/brands', 'placeholders/ComingSoon')->name('brands.index');
    Route::inertia('/payments', 'placeholders/ComingSoon')->name('payments.index');
});

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('users', AdminUserController::class)
            ->except(['show']);
    });

// Phase 5 stub — MUST be outside auth group (D-07: /pay/{uuid} reachable without session)
Route::get('/pay/{uuid}', fn () => abort(404))->name('pay.show');

require __DIR__.'/settings.php';
