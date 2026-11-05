<?php

namespace App\Services\Kurv;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled backstop (spec 0010, AC-10): looks again at pending Kurv payments whose link is
 * between a couple of minutes and 72 hours old and that were not checked recently. It makes
 * read only calls, never creates a Kurv request, ignores whether the account is active, and
 * uses no delayed jobs, so it also works with QUEUE_CONNECTION=sync.
 */
class KurvStalePaymentSweep
{
    public function __invoke(): void
    {
        $resolver = app(KurvChargeResolver::class);

        $minAge = (int) config('services.kurv.min_age_seconds', 120);
        $gap = (int) config('services.kurv.lookup_gap_seconds', 300);
        $window = (int) config('services.kurv.sweep_hours', 72);

        $due = Payment::query()
            ->where('provider', PaymentProvider::Kurv->value)
            ->where('status', 'pending')
            ->whereNotNull('kurv_request_id')
            ->whereNull('kurv_link_closed_at')
            ->where('kurv_charge_started_at', '<=', now()->subSeconds($minAge))
            ->where('kurv_charge_started_at', '>=', now()->subHours($window))
            ->where(fn ($query) => $query
                ->whereNull('kurv_last_lookup_at')
                ->orWhere('kurv_last_lookup_at', '<=', now()->subSeconds($gap)))
            ->get();

        foreach ($due as $payment) {
            try {
                $resolver->resolve($payment, KurvChargeResolver::SOURCE_SWEEP);
            } catch (Throwable $e) {
                // One bad payment never stops the others.
                Log::warning('Kurv sweep failed for a payment', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        $stuck = Payment::query()
            ->where('provider', PaymentProvider::Kurv->value)
            ->where('status', 'pending')
            ->whereNotNull('kurv_request_id')
            ->whereNull('kurv_link_closed_at')
            ->where('kurv_charge_started_at', '<', now()->subHours($window))
            ->count();

        if ($stuck > 0) {
            Log::warning('Kurv payments are still pending past the sweep window', ['count' => $stuck]);
        }
    }
}
