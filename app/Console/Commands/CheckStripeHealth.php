<?php

namespace App\Console\Commands;

use App\Models\StripeAccount;
use App\Services\Stripe\StripeAccountHealthChecker;
use Illuminate\Console\Command;

class CheckStripeHealth extends Command
{
    protected $signature = 'stripe:check-health {account? : Stripe account id (default: all)}';

    protected $description = 'Check whether each Stripe account can still take payments and save the result';

    public function handle(StripeAccountHealthChecker $checker): int
    {
        $id = $this->argument('account');

        $accounts = $id === null
            ? StripeAccount::orderBy('id')->get()
            : StripeAccount::whereKey($id)->get();

        if ($id !== null && $accounts->isEmpty()) {
            $this->error("Stripe account {$id} not found.");

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            $health = $checker->check($account);
            $this->line("#{$account->id} {$account->account_name}: {$health->status->value}");
        }

        return self::SUCCESS;
    }
}
