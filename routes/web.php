<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\RelationshipManagerController;
use App\Http\Controllers\Admin\RevolutAccountController;
use App\Http\Controllers\Admin\SquareAccountController;
use App\Http\Controllers\Admin\StripeAccountController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\VivaAccountController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\ClientPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RevolutWebhookController;
use App\Http\Controllers\SquareWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\VivaWebhookController;
use App\Support\Navigation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Auth::check()
        ? redirect(Navigation::homePathFor(Auth::user()))
        : redirect()->route('login');
})->name('home');

if (app()->isLocal()) {
    Route::get('/dev/errors/{status}', fn (int $status) => Inertia::render('Error', ['status' => $status]))
        ->whereNumber('status');
}

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

    Route::resource('bank-accounts', BankAccountController::class)->except(['show']);

    Route::patch('bank-accounts/{bank_account}/deactivate', [BankAccountController::class, 'deactivate'])
        ->name('bank-accounts.deactivate');

    Route::patch('bank-accounts/{bank_account}/activate', [BankAccountController::class, 'activate'])
        ->name('bank-accounts.activate');
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

        Route::resource('revolut-accounts', RevolutAccountController::class)
            ->except(['show']);

        Route::patch(
            'revolut-accounts/{revolut_account}/deactivate',
            [RevolutAccountController::class, 'deactivate']
        )->name('revolut-accounts.deactivate');

        Route::patch(
            'revolut-accounts/{revolut_account}/activate',
            [RevolutAccountController::class, 'activate']
        )->name('revolut-accounts.activate');

        Route::post(
            'revolut-accounts/test-connection',
            [RevolutAccountController::class, 'testKeyConnection']
        )->name('revolut-accounts.test-connection');

        Route::post(
            'revolut-accounts/{revolut_account}/test-connection',
            [RevolutAccountController::class, 'testStoredConnection']
        )->name('revolut-accounts.test-stored-connection');

        Route::resource('square-accounts', SquareAccountController::class)
            ->except(['show']);

        Route::patch(
            'square-accounts/{square_account}/deactivate',
            [SquareAccountController::class, 'deactivate']
        )->name('square-accounts.deactivate');

        Route::patch(
            'square-accounts/{square_account}/activate',
            [SquareAccountController::class, 'activate']
        )->name('square-accounts.activate');

        Route::post(
            'square-accounts/test-connection',
            [SquareAccountController::class, 'testKeyConnection']
        )->name('square-accounts.test-connection');

        Route::post(
            'square-accounts/{square_account}/test-connection',
            [SquareAccountController::class, 'testStoredConnection']
        )->name('square-accounts.test-stored-connection');

        Route::resource('viva-accounts', VivaAccountController::class)
            ->except(['show']);

        Route::patch(
            'viva-accounts/{viva_account}/deactivate',
            [VivaAccountController::class, 'deactivate']
        )->name('viva-accounts.deactivate');

        Route::patch(
            'viva-accounts/{viva_account}/activate',
            [VivaAccountController::class, 'activate']
        )->name('viva-accounts.activate');

        Route::post(
            'viva-accounts/test-connection',
            [VivaAccountController::class, 'testKeyConnection']
        )->name('viva-accounts.test-connection');

        Route::post(
            'viva-accounts/{viva_account}/test-connection',
            [VivaAccountController::class, 'testStoredConnection']
        )->name('viva-accounts.test-stored-connection');
    });

// Public payment routes — no auth middleware (CLIENT-01)
// {payment} matches controller $payment param; getRouteKeyName()='uuid' resolves by UUID value
Route::get('/pay/{payment}', [ClientPaymentController::class, 'show'])->name('pay.show');
Route::post('/pay/{payment}/consent', [ClientPaymentController::class, 'storeConsent'])->name('pay.consent');
Route::get('/pay/{payment}/success', [ClientPaymentController::class, 'success'])->name('pay.success');
Route::get('/pay/{payment}/failed', [ClientPaymentController::class, 'failed'])->name('pay.failed');

// Viva Smart Checkout return endpoints — the Success/Failure URL is configured once per
// VivaAccount in Viva's own dashboard (self-care), a static URL that can't embed our
// per-payment {payment} route segment. Viva instead appends `?s={orderCode}` (plus `t`,
// `lang`, `eventId`) to whatever static URL is configured — resolve the Payment via the
// order code and hand off to the existing per-payment success/failed page.
// Paths match what's already configured in the Viva dashboard (https://payhub.test/success
// and https://payhub.test/failed) so no dashboard change is needed per account.
Route::get('/success', [ClientPaymentController::class, 'vivaReturnSuccess'])->name('pay.viva.return.success');
Route::get('/failed', [ClientPaymentController::class, 'vivaReturnFailed'])->name('pay.viva.return.failed');

// Square embedded charge endpoint — public, no auth, CSRF-excluded, throttled.
// Authoritative status still comes from the payment.updated webhook (CLAUDE.md rule).
Route::post('/pay/{payment}/square', [ClientPaymentController::class, 'chargeSquare'])
    ->name('pay.square.charge')
    ->middleware('throttle:30,1');

// Webhook routes — public, no auth middleware, no CSRF (SEC-03)
// {stripeAccount} resolves by integer id (implicit model binding — StripeAccount has no getRouteKeyName() override)
Route::post('/webhook/stripe/{stripeAccount}', [StripeWebhookController::class, 'handle'])
    ->name('webhook.stripe')
    ->middleware('throttle:120,1');

// {revolutAccount} resolves by integer id (implicit model binding)
Route::post('/webhook/revolut/{revolutAccount}', [RevolutWebhookController::class, 'handle'])
    ->name('webhook.revolut')
    ->middleware('throttle:120,1');

// {squareAccount} resolves by integer id (implicit model binding)
Route::post('/webhook/square/{squareAccount}', [SquareWebhookController::class, 'handle'])
    ->name('webhook.square')
    ->middleware('throttle:120,1');

// {vivaAccount} resolves by integer id (implicit model binding).
// GET: Viva's one-time verification handshake, sent before any signed POSTs.
// POST: signed TransactionPaymentCreated event delivery.
Route::get('/webhook/viva/{vivaAccount}', [VivaWebhookController::class, 'verify'])
    ->name('webhook.viva.verify')
    ->middleware('throttle:120,1');

Route::post('/webhook/viva/{vivaAccount}', [VivaWebhookController::class, 'handle'])
    ->name('webhook.viva')
    ->middleware('throttle:120,1');

// TEMPORARY deploy hatch — clears app caches AND resets PHP opcache so freshly
// deployed code takes effect without a php-fpm restart. Token-gated to avoid a
// public DoS/abuse vector. CHANGE the token below, and REMOVE this route once
// the deploy is confirmed.
Route::get('/__maint/clear-cache/{token}', function (string $token) {
    abort_unless(hash_equals('f94ce57632357dbf9b69d7c8022d2b85c2d04ba09feeba63e58cb44233fdfce5', $token), 404);

    Artisan::call('optimize:clear'); // config, route, view, event, compiled, cache

    $opcache = function_exists('opcache_reset') ? opcache_reset() : null;

    return response()->json([
        'optimize_clear' => trim(Artisan::output()),
        'opcache_reset' => $opcache,
        'at' => now()->toIso8601String(),
    ]);
})->middleware('throttle:5,1')->name('maint.clear-cache');

require __DIR__.'/settings.php';
