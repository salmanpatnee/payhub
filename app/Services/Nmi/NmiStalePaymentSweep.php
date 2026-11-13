<?php

namespace App\Services\Nmi;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled backstop (spec 0012, AC-15): looks again at pending NMI payments charged in the last 48 hours.
 * Read only; it never creates a charge and ignores `is_active`. A payment NMI still has no transaction for
 * 30 minutes after its last attempt is given up on: warned once, its unclear attempt row marked error, and
 * the pause cleared so the client can try again. At most one call per second per account.
 */
class NmiStalePaymentSweep
{
    public const GIVE_UP_REASON = 'No transaction found at NMI';

    public function __invoke(): void
    {
        $resolver = app(NmiChargeResolver::class);

        $payments = Payment::query()
            ->with('nmiAccount')
            ->where('provider', PaymentProvider::Nmi->value)
            ->where('status', 'pending')
            ->whereNotNull('nmi_order_id')
            ->where('nmi_last_attempt_at', '>=', now()->subHours((int) config('services.nmi.sweep_hours', 48)))
            ->get();

        foreach ($payments->groupBy('nmi_account_id') as $group) {
            foreach ($group->values() as $i => $payment) {
                if ($i > 0) {
                    usleep(1_000_000); // one call per second per account
                }

                try {
                    $this->sweep($payment, $resolver);
                } catch (Throwable $e) {
                    // One bad payment never stops the others.
                    Log::warning('NMI sweep failed for a payment', ['payment_id' => $payment->id, 'exception' => $e::class]);
                }
            }
        }
    }

    private function sweep(Payment $payment, NmiChargeResolver $resolver): void
    {
        $last = $payment->nmiChargeAttempts()->orderByDesc('sequence')->first();

        if ($last?->reason === self::GIVE_UP_REASON) {
            return; // already given up on; a new charge starts a new attempt row
        }

        $outcome = $resolver->resolve($payment);

        if ($outcome !== NmiChargeResolver::OUTCOME_NOT_FOUND
            || $payment->nmi_last_attempt_at === null
            || $payment->nmi_last_attempt_at->gt(now()->subMinutes((int) config('services.nmi.give_up_minutes', 30)))) {
            return;
        }

        Log::warning('NMI found no transaction for a charged payment, the sweep stops looking', ['payment_id' => $payment->id]);

        $last?->update(['status' => 'error', 'reason' => self::GIVE_UP_REASON]);
        Cache::forget("nmi:pause:{$payment->id}");
    }
}
