<?php

namespace App\Providers;

use App\Models\BankAccount;
use App\Models\Payment;
use App\Policies\BankAccountPolicy;
use App\Policies\PaymentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);

        // Payment-UUID keyed, not the default IP keyed throttle — the pay page is
        // public and unauthenticated, so IP alone would let one client exhaust every
        // other client's attempts (or a client behind a shared/proxy IP exhaust their
        // own too early). See spec 0002 (Clover Hosted Iframe), AC-11.
        //
        // route('payment') is the raw route-parameter STRING here, not a bound
        // Payment model — throttle middleware runs before SubstituteBindings
        // resolves it (confirmed live, 2026-09-18: it was always falling through
        // to the IP fallback, so this throttle was silently IP-keyed for every
        // client the whole time). Payment's getRouteKeyName() is 'uuid', so the
        // raw string already *is* the uuid — no ->uuid access needed or possible.
        RateLimiter::for('clover-charge', fn (Request $request): Limit => Limit::perHour(10)->by(
            $request->route('payment') ?? $request->ip()
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(6));
    }
}
