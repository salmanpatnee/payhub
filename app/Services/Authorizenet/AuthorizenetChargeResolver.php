<?php

namespace App\Services\Authorizenet;

use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The one place Authorize.net status writes happen (spec 0006, AC-7). Shared by
 * the status endpoint, the webhook job and the sweep, with the same two guards
 * the other providers use: whereIn('status', ['pending', 'failed']) for
 * completion, where('status', 'pending') for a decline.
 */
class AuthorizenetChargeResolver
{
    /**
     * Apply one classified transaction to its payment.
     *
     * @param  array<string, mixed>  $transaction  the `transaction` object from getTransactionDetails
     */
    public function apply(Payment $payment, string $bucket, string $transactionId, array $transaction = []): void
    {
        $payment->refresh();

        match ($bucket) {
            AuthorizenetChargeClassifier::APPROVED => $this->approve($payment, $transactionId),
            AuthorizenetChargeClassifier::HELD => $this->hold($payment, $transactionId),
            AuthorizenetChargeClassifier::DECLINED => $this->decline($payment, $transactionId),
            default => $this->unknown($payment, $transactionId, $transaction),
        };
    }

    private function approve(Payment $payment, string $transactionId): void
    {
        $updated = Payment::whereKey($payment->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'completed',
                'paid_at' => now(),
                'authorizenet_transaction_id' => $transactionId,
            ]);

        if ($updated > 0) {
            SendPaymentNotification::dispatch($payment->fresh());

            return;
        }

        if ($payment->status === 'completed' && $payment->authorizenet_transaction_id === $transactionId) {
            return; // the same transaction seen again
        }

        // A second approved transaction for a paid payment, or one for a cancelled payment.
        // Refunds are out of scope, so this is only made visible to operators (reaches Sentry).
        Log::error('Authorize.net approved transaction was not applied', [
            'payment_id' => $payment->id,
            'payment_status' => $payment->status,
            'stored_transaction_id' => $payment->authorizenet_transaction_id,
            'transaction_id' => $transactionId,
        ]);
    }

    private function hold(Payment $payment, string $transactionId): void
    {
        Payment::whereKey($payment->id)
            ->where('status', 'pending')
            ->whereNull('authorizenet_transaction_id')
            ->update(['authorizenet_transaction_id' => $transactionId]);
    }

    private function decline(Payment $payment, string $transactionId): void
    {
        // Only a held transaction that was then declined (fraud review) ends the payment.
        // A plain decline stays in the hosted form where the client retries.
        if ($payment->authorizenet_transaction_id === $transactionId) {
            Payment::whereKey($payment->id)->where('status', 'pending')->update(['status' => 'failed']);
        }
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function unknown(Payment $payment, string $transactionId, array $transaction): void
    {
        // Once per transaction per hour, so a polling client cannot flood the logs.
        if (! Cache::add("authorizenet:unknown:{$transactionId}", 1, now()->addHour())) {
            return;
        }

        Log::error('Authorize.net transaction could not be classified', [
            'payment_id' => $payment->id,
            'transaction_id' => $transactionId,
            'response_code' => $transaction['responseCode'] ?? null,
            'transaction_status' => $transaction['transactionStatus'] ?? null,
        ]);
    }
}
