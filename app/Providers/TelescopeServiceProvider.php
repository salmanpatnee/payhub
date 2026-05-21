<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        Telescope::night();

        $this->hideSensitiveRequestDetails();
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            return $user->hasRole('admin');
        });
    }

    private function hideSensitiveRequestDetails(): void
    {
        if ($this->app->isLocal()) {
            return;
        }

        Telescope::hideRequestParameters(['password', 'secret_key', 'webhook_secret', 'clientSecret']);
        Telescope::hideRequestHeaders(['Authorization', 'Stripe-Signature', 'Cookie']);
    }
}
