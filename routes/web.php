<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\StripeAccountController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('/payments', 'placeholders/ComingSoon')->name('payments.index');
});

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('users', AdminUserController::class)
            ->except(['show']);

        Route::resource('brands', BrandController::class)
            ->except(['show', 'destroy']);

        Route::resource('brands.stripe-accounts', StripeAccountController::class)
            ->except(['show'])
            ->scoped(['stripe_account' => 'id']);

        Route::patch(
            'brands/{brand}/stripe-accounts/{stripe_account}/deactivate',
            [StripeAccountController::class, 'deactivate']
        )->name('brands.stripe-accounts.deactivate');
    });

// Phase 5 stub — MUST be outside auth group (D-07: /pay/{uuid} reachable without session)
Route::get('/pay/{uuid}', fn () => abort(404))->name('pay.show');

require __DIR__.'/settings.php';
