<?php

namespace App\Services\Worldpay;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled backstop (spec 0014, AC-11): looks again at Worldpay payments whose newest page is
 * between a couple of minutes and 72 hours old and that were not checked recently. A payment
 * that is `pending` is looked at to find a payment; a `completed` payment with more than one
 * page is looked at only to log a late second sale. It makes read only calls, never creates a
 * page, ignores whether the account is active, uses no delayed jobs (so it also works with
 * QUEUE_CONNECTION=sync) and makes at most one Worldpay call per second per account.
 */
class WorldpayStalePaymentSweep
{
    public function __invoke(): void
    {
        $resolver = app(WorldpayChargeResolver::class);

        $minAge = (int) config('services.worldpay.min_age_seconds', 120);
        $gap = (int) config('services.worldpay.lookup_gap_seconds', 300);
        $hours = (int) config('services.worldpay.sweep_hours', 72);

        $inWindow = fn ($page) => $page->whereNull('failed_at')->whereNotNull('url');

        $due = Payment::query()
            ->where('provider', PaymentProvider::Worldpay->value)
            ->where(fn ($q) => $q
                ->where('status', 'pending')
                ->orWhere(fn ($q2) => $q2->where('status', 'completed')
                    ->whereHas('worldpayPages', fn ($p) => $inWindow($p), '>', 1)))
            ->whereHas('worldpayPages', fn ($p) => $inWindow($p))
            ->whereRaw('(select max(created_at) from worldpay_pages where worldpay_pages.payment_id = payments.id and failed_at is null and url is not null) <= ?', [now()->subSeconds($minAge)])
            ->whereRaw('(select max(created_at) from worldpay_pages where worldpay_pages.payment_id = payments.id and failed_at is null and url is not null) >= ?', [now()->subHours($hours)])
            ->where(fn ($q) => $q
                ->whereNull('worldpay_last_lookup_at')
                ->orWhere('worldpay_last_lookup_at', '<=', now()->subSeconds($gap)))
            ->get();

        foreach ($due->groupBy('worldpay_account_id') as $group) {
            foreach ($group->values() as $i => $payment) {
                if ($i > 0) {
                    usleep(1_000_000); // one call per second per account
                }

                try {
                    $resolver->resolve($payment, WorldpayChargeResolver::SOURCE_SWEEP);
                } catch (Throwable $e) {
                    // One bad payment never stops the others.
                    Log::warning('Worldpay sweep failed for a payment', ['payment_id' => $payment->id, 'exception' => $e::class]);
                }
            }
        }

        $stuck = Payment::query()
            ->where('provider', PaymentProvider::Worldpay->value)
            ->where('status', 'pending')
            ->whereHas('worldpayPages', fn ($p) => $inWindow($p))
            ->whereRaw('(select max(created_at) from worldpay_pages where worldpay_pages.payment_id = payments.id and failed_at is null and url is not null) < ?', [now()->subHours($hours)])
            ->count();

        if ($stuck > 0) {
            Log::warning('Worldpay payments are still pending past the sweep window', ['count' => $stuck]);
        }
    }
}
