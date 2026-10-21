<?php

namespace App\Services\Authorizenet;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled backstop (spec 0006, AC-11): re-reads pending Authorize.net payments
 * whose token was issued in the last 24 hours, one list pass per account. Read
 * only; it never creates a charge.
 */
class AuthorizenetStalePaymentSweep
{
    public function __invoke(): void
    {
        $lookup = app(AuthorizenetInvoiceLookup::class);

        $payments = Payment::query()
            ->with('authorizenetAccount')
            ->where('provider', PaymentProvider::Authorizenet->value)
            ->where('status', 'pending')
            ->where('authorizenet_token_last_issued_at', '>=', now()->subDay())
            ->get();

        $old = Payment::query()
            ->where('provider', PaymentProvider::Authorizenet->value)
            ->where('status', 'pending')
            ->whereNull('authorizenet_transaction_id')
            ->where('authorizenet_token_last_issued_at', '<', now()->subDay())
            ->count();

        if ($old > 0) {
            Log::warning('Authorize.net pending payments older than 24 hours with no transaction id were left alone', ['count' => $old]);
        }

        foreach ($payments->groupBy('authorizenet_account_id') as $group) {
            $account = $group->first()->authorizenetAccount;

            if (! $account?->is_active) {
                continue;
            }

            try {
                $lookup->sweepAccount($account, $group);
            } catch (Throwable $e) {
                // One bad account never stops the others.
                Log::warning('Authorize.net sweep failed for an account', ['account_id' => $account->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
