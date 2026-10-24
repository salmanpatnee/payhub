<?php

use App\Http\Controllers\Admin\AuthorizenetAccountController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CloverAccountController;
use App\Http\Controllers\Admin\MpgsAccountController;
use App\Http\Controllers\Admin\RelationshipManagerController;
use App\Http\Controllers\Admin\RevolutAccountController;
use App\Http\Controllers\Admin\SquareAccountController;
use App\Http\Controllers\Admin\StripeAccountController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\VivaAccountController;
use App\Http\Controllers\AuthorizenetPaymentController;
use App\Http\Controllers\AuthorizenetWebhookController;
use App\Http\Controllers\BankAccountActivityLogController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\ClientPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MpgsPaymentController;
use App\Http\Controllers\MpgsWebhookController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RevolutWebhookController;
use App\Http\Controllers\SquareWebhookController;
use App\Http\Controllers\StripeHealthController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\VivaWebhookController;
use App\Http\Controllers\ZelleAccountController;
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
    Route::middleware('role:admin|account')->group(function () {
        Route::get('stripe-health', [StripeHealthController::class, 'index'])
            ->name('stripe-health.index');
        // Registered before the {stripe_account} route so `check` is not read as an id.
        Route::post('stripe-health/check', [StripeHealthController::class, 'checkAll'])
            ->name('stripe-health.check-all')
            ->middleware('throttle:10,1');
        Route::post('stripe-health/{stripe_account}/check', [StripeHealthController::class, 'check'])
            ->name('stripe-health.check')
            ->middleware('throttle:10,1');
    });

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
    Route::patch('payments/{payment}/reset-lock', [PaymentController::class, 'resetLock'])
        ->name('payments.reset-lock')
        ->middleware('throttle:30,1');

    // Must be registered before the resource route — otherwise `bank-accounts/activity-log`
    // is captured by the `bank-accounts/{bank_account}/edit` show/edit routes.
    Route::get('bank-accounts/activity-log', [BankAccountActivityLogController::class, 'index'])
        ->name('bank-accounts.activity-log');

    Route::resource('bank-accounts', BankAccountController::class)->except(['show']);

    Route::patch('bank-accounts/{bank_account}/deactivate', [BankAccountController::class, 'deactivate'])
        ->name('bank-accounts.deactivate');

    Route::patch('bank-accounts/{bank_account}/activate', [BankAccountController::class, 'activate'])
        ->name('bank-accounts.activate');

    Route::resource('zelle-accounts', ZelleAccountController::class)->except(['show']);

    Route::patch('zelle-accounts/{zelle_account}/deactivate', [ZelleAccountController::class, 'deactivate'])
        ->name('zelle-accounts.deactivate');

    Route::patch('zelle-accounts/{zelle_account}/activate', [ZelleAccountController::class, 'activate'])
        ->name('zelle-accounts.activate');
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

        Route::resource('clover-accounts', CloverAccountController::class)
            ->except(['show']);

        Route::patch(
            'clover-accounts/{clover_account}/deactivate',
            [CloverAccountController::class, 'deactivate']
        )->name('clover-accounts.deactivate');

        Route::patch(
            'clover-accounts/{clover_account}/activate',
            [CloverAccountController::class, 'activate']
        )->name('clover-accounts.activate');

        Route::post(
            'clover-accounts/test-connection',
            [CloverAccountController::class, 'testKeyConnection']
        )->name('clover-accounts.test-connection');

        Route::post(
            'clover-accounts/{clover_account}/test-connection',
            [CloverAccountController::class, 'testStoredConnection']
        )->name('clover-accounts.test-stored-connection');

        Route::resource('mpgs-accounts', MpgsAccountController::class)
            ->except(['show']);

        Route::patch(
            'mpgs-accounts/{mpgs_account}/deactivate',
            [MpgsAccountController::class, 'deactivate']
        )->name('mpgs-accounts.deactivate');

        Route::patch(
            'mpgs-accounts/{mpgs_account}/activate',
            [MpgsAccountController::class, 'activate']
        )->name('mpgs-accounts.activate');

        Route::post(
            'mpgs-accounts/test-connection',
            [MpgsAccountController::class, 'testKeyConnection']
        )->name('mpgs-accounts.test-connection');

        Route::post(
            'mpgs-accounts/{mpgs_account}/test-connection',
            [MpgsAccountController::class, 'testStoredConnection']
        )->name('mpgs-accounts.test-stored-connection');

        Route::resource('authorizenet-accounts', AuthorizenetAccountController::class)
            ->except(['show']);

        Route::patch(
            'authorizenet-accounts/{authorizenet_account}/deactivate',
            [AuthorizenetAccountController::class, 'deactivate']
        )->name('authorizenet-accounts.deactivate');

        Route::patch(
            'authorizenet-accounts/{authorizenet_account}/activate',
            [AuthorizenetAccountController::class, 'activate']
        )->name('authorizenet-accounts.activate');

        Route::post(
            'authorizenet-accounts/test-connection',
            [AuthorizenetAccountController::class, 'testKeyConnection']
        )->name('authorizenet-accounts.test-connection');

        Route::post(
            'authorizenet-accounts/{authorizenet_account}/test-connection',
            [AuthorizenetAccountController::class, 'testStoredConnection']
        )->name('authorizenet-accounts.test-stored-connection');
    });

// Public payment routes — no auth middleware (CLIENT-01)
// {payment} matches controller $payment param; getRouteKeyName()='uuid' resolves by UUID value
Route::get('/pay/{payment}', [ClientPaymentController::class, 'show'])->name('pay.show');
Route::post('/pay/{payment}/consent', [ClientPaymentController::class, 'storeConsent'])->name('pay.consent');
Route::get('/pay/{payment}/success', [ClientPaymentController::class, 'success'])->name('pay.success');
Route::get('/pay/{payment}/failed', [ClientPaymentController::class, 'failed'])->name('pay.failed');
// Same page with a readable reference code (PREFIX-000000 or 000000); the uuid alone resolves the payment, {ref}
// is ignored. Must stay after success/failed so those words are never taken as a ref. Must end in a digit.
Route::get('/pay/{payment}/{ref}', [ClientPaymentController::class, 'show'])->where('ref', '[A-Za-z0-9_-]*[0-9]')->name('pay.show.ref');

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

// Clover embedded charge endpoint — public, no auth, CSRF-excluded. Rate limited
// per payment (not IP) via the named 'clover-charge' limiter (spec 0002, AC-11) —
// unlike every other PayHub provider, the synchronous response here IS the
// authoritative status source, so this route needs its own throttle shape.
Route::post('/pay/{payment}/clover/charge', [ClientPaymentController::class, 'chargeClover'])
    ->name('pay.clover.charge')
    ->middleware('throttle:clover-charge');

// Polled by the client only while the latest charge attempt is unresolved (AC-9).
Route::get('/pay/{payment}/clover/status', [ClientPaymentController::class, 'cloverStatus'])
    ->name('pay.clover.status');

// MPGS Hosted Session pay thread — public (scoped by the payment UUID), rate limited
// per payment by the named limiters in AppServiceProvider. Unlike Clover these stay
// behind CSRF: the page sends the XSRF token (spec 0005, AC-12).
Route::post('/pay/{payment}/mpgs/session', [MpgsPaymentController::class, 'session'])
    ->name('pay.mpgs.session')
    ->middleware('throttle:mpgs-session');
Route::post('/pay/{payment}/mpgs/pay', [MpgsPaymentController::class, 'pay'])
    ->name('pay.mpgs.pay')
    ->middleware('throttle:mpgs-pay');
Route::get('/pay/{payment}/mpgs/status', [MpgsPaymentController::class, 'status'])
    ->name('pay.mpgs.status');
// The bank's ACS posts the payer back here after a 3DS challenge (CSRF excluded, it is a
// cross site POST). Renders inside the challenge frame on the pay page.
Route::match(['get', 'post'], '/pay/{payment}/mpgs/3ds-return', [MpgsPaymentController::class, 'threeDsReturn'])
    ->name('pay.mpgs.return');

// Authorize.net Accept.js pay thread (spec 0007) — public (scoped by the payment UUID).
// The charge POST stays behind CSRF and the authorizenet-charge limiter; status is bounded by the
// lookup guards in AuthorizenetInvoiceLookup, not by a named limiter.
Route::post('/pay/{payment}/authorizenet/charge', [AuthorizenetPaymentController::class, 'charge'])
    ->name('pay.authorizenet.charge')
    ->middleware('throttle:authorizenet-charge');
Route::get('/pay/{payment}/authorizenet/status', [AuthorizenetPaymentController::class, 'status'])
    ->name('pay.authorizenet.status');

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

// {mpgsAccount} resolves by integer id (implicit model binding). Protected by the per account
// X-Notification-Secret header, not a signature (spec 0005, AC-9).
Route::post('/webhook/mpgs/{mpgsAccount}', [MpgsWebhookController::class, 'handle'])
    ->name('webhook.mpgs')
    ->middleware('throttle:120,1');

// {authorizenetAccount} resolves by integer id. Protected by the X-ANET-Signature HMAC (spec 0006, AC-8).
Route::post('/webhook/authorizenet/{authorizenetAccount}', [AuthorizenetWebhookController::class, 'handle'])
    ->name('webhook.authorizenet')
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
