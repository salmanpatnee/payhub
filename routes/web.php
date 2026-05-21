<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\RelationshipManagerController;
use App\Http\Controllers\Admin\StripeAccountController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\ClientPaymentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('payments.index')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::resource('payments', PaymentController::class)
        ->only(['index', 'create', 'store', 'show']);
});

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('users', AdminUserController::class)
            ->except(['show']);

        Route::resource('brands', BrandController::class)
            ->except(['show']);

        Route::resource('stripe-accounts', StripeAccountController::class)
            ->except(['show']);

        Route::resource('relationship-managers', RelationshipManagerController::class)
            ->except(['show']);

        Route::patch(
            'stripe-accounts/{stripe_account}/deactivate',
            [StripeAccountController::class, 'deactivate']
        )->name('stripe-accounts.deactivate');

        Route::patch(
            'stripe-accounts/{stripe_account}/activate',
            [StripeAccountController::class, 'activate']
        )->name('stripe-accounts.activate');

        Route::post(
            'stripe-accounts/test-connection',
            [StripeAccountController::class, 'testKeyConnection']
        )->name('stripe-accounts.test-connection');

        Route::post(
            'stripe-accounts/{stripe_account}/test-connection',
            [StripeAccountController::class, 'testStoredConnection']
        )->name('stripe-accounts.test-stored-connection');
    });

// Public payment routes — no auth middleware (CLIENT-01)
// {payment} matches controller $payment param; getRouteKeyName()='uuid' resolves by UUID value
Route::get('/pay/{payment}', [ClientPaymentController::class, 'show'])->name('pay.show');
Route::get('/pay/{payment}/success', [ClientPaymentController::class, 'success'])->name('pay.success');
Route::get('/pay/{payment}/failed', [ClientPaymentController::class, 'failed'])->name('pay.failed');

// Webhook routes — public, no auth middleware, no CSRF (SEC-03)
// {stripeAccount} resolves by integer id (implicit model binding — StripeAccount has no getRouteKeyName() override)
Route::post('/webhook/stripe/{stripeAccount}', [StripeWebhookController::class, 'handle'])
    ->name('webhook.stripe');

require __DIR__.'/settings.php';
