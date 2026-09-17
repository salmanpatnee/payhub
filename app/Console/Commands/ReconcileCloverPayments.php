<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Clover\CloverClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backstop for the rare case a Clover webhook never arrives: a payment still
 * pending a grace period after its checkout session expired is looked up
 * directly against Clover's Payments API. Never overrides a payment already
 * completed/failed by webhook (each update is guarded by a fresh
 * where('status', 'pending') at write time).
 *
 * The exact result field name/enum on Clover's v3 Payments API response is
 * not confirmed against a live sandbox (Clover publishes no full schema for
 * it) — see CloverClient::getCharge()'s docstring and spec 0001's Follow-up.
 * Confirm before relying on this beyond a best-effort backstop.
 */
class ReconcileCloverPayments extends Command
{
    protected $signature = 'clover:reconcile-payments';

    protected $description = 'Reconcile Clover payments stuck pending after their checkout session expired';

    private const GRACE_MINUTES = 15;

    public function handle(): int
    {
        Payment::query()
            ->where('provider', 'clover')
            ->where('status', 'pending')
            ->whereNotNull('clover_checkout_session_id')
            ->whereNotNull('clover_checkout_expires_at')
            ->where('clover_checkout_expires_at', '<', now()->subMinutes(self::GRACE_MINUTES))
            ->with('cloverAccount')
            ->each(fn (Payment $payment) => $this->reconcile($payment));

        return self::SUCCESS;
    }

    private function reconcile(Payment $payment): void
    {
        if ($payment->cloverAccount === null) {
            return;
        }

        $clover = app()->make(CloverClient::class, [
            'merchantId' => $payment->cloverAccount->merchant_id,
            'privateToken' => $payment->cloverAccount->private_token,
            'environment' => $payment->cloverAccount->environment,
        ]);

        try {
            $charge = $clover->getCharge($payment->clover_checkout_session_id);
        } catch (\Throwable $e) {
            Log::warning('clover.reconcile.lookup_failed', [
                'payment_id' => $payment->id,
                'checkout_session_id' => $payment->clover_checkout_session_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($charge === null) {
            return;
        }

        $result = $charge['result'] ?? null;
        $cloverPaymentId = $charge['id'] ?? null;

        if ($result === 'SUCCESS') {
            Payment::where('id', $payment->id)
                ->where('status', 'pending')
                ->update(['status' => 'completed', 'paid_at' => now(), 'clover_payment_id' => $cloverPaymentId]);
        } elseif (in_array($result, ['FAILED', 'DECLINED'], true)) {
            Payment::where('id', $payment->id)
                ->where('status', 'pending')
                ->update(['status' => 'failed', 'clover_payment_id' => $cloverPaymentId]);
        }
    }
}
