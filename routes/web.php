<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\RelationshipManagerController;
use App\Http\Controllers\Admin\StripeAccountController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\ClientPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StripeWebhookController;
use App\Support\Navigation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect(Navigation::homePathFor(Auth::user()))
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('role:admin|account');
    // Must be registered before the resource route — otherwise `payments/export`
    // is captured by the `payments/{payment}` show route (payment = "export").
    Route::get('payments/export', [PaymentController::class, 'export'])
        ->name('payments.export');
    Route::resource('payments', PaymentController::class)
        ->only(['index', 'create', 'show']);
    Route::post('payments', [PaymentController::class, 'store'])
        ->name('payments.store')
        ->middleware('throttle:30,1');
    Route::get('payments/{payment}/edit', [PaymentController::class, 'edit'])
        ->name('payments.edit');
    Route::patch('payments/{payment}', [PaymentController::class, 'update'])
        ->name('payments.update')
        ->middleware('throttle:30,1');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])
        ->name('payments.destroy');
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
            'relationship-managers/{relationshipManager}/deactivate',
            [RelationshipManagerController::class, 'deactivate']
        )->name('relationship-managers.deactivate');

        Route::patch(
            'relationship-managers/{relationshipManager}/activate',
            [RelationshipManagerController::class, 'activate']
        )->name('relationship-managers.activate');

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
Route::post('/pay/{payment}/consent', [ClientPaymentController::class, 'storeConsent'])->name('pay.consent');
Route::get('/pay/{payment}/success', [ClientPaymentController::class, 'success'])->name('pay.success');
Route::get('/pay/{payment}/failed', [ClientPaymentController::class, 'failed'])->name('pay.failed');

// Webhook routes — public, no auth middleware, no CSRF (SEC-03)
// {stripeAccount} resolves by integer id (implicit model binding — StripeAccount has no getRouteKeyName() override)
Route::post('/webhook/stripe/{stripeAccount}', [StripeWebhookController::class, 'handle'])
    ->name('webhook.stripe')
    ->middleware('throttle:120,1');

require __DIR__.'/settings.php';
