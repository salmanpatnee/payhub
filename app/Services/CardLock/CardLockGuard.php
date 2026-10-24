<?php

namespace App\Services\CardLock;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

/**
 * Pauses the pay page of an MPGS or Authorize.net payment after too many failed card
 * attempts, so repeated declines do not hurt the merchant's health. The count comes from
 * the attempt tables (so a late decline found by a lookup counts too), starting at
 * card_attempts_since. Only real declines count: unclear, held and error replies do not.
 */
class CardLockGuard
{
    /** Authorize.net attempt statuses that count as a failed card. */
    private const ANET_FAILED = ['declined', 'check_details'];

    public const MESSAGE = 'Too many unsuccessful attempts. For your security this payment is paused for a while. Please contact your representative to help you complete it.';

    /**
     * Call after a decline is written. Locks the payment once the limit is reached.
     */
    public function recordFailure(Payment $payment): void
    {
        $payment->refresh();

        if ($payment->isCardLocked() || ! $this->applies($payment)) {
            return;
        }

        if ($this->failedCount($payment) < (int) config('services.card_lock.max_attempts')) {
            return;
        }

        $until = now()->addMinutes((int) config('services.card_lock.minutes'));

        $payment->forceFill([
            'card_locked_until' => $until,
            'card_attempts_since' => $until, // attempts after the lock ends start a fresh count
        ])->save();
    }

    public function isLocked(Payment $payment): bool
    {
        return $payment->isCardLocked();
    }

    /**
     * What the pay page JSON endpoints answer while the payment is locked (HTTP 423).
     */
    public function lockedResponse(Payment $payment): JsonResponse
    {
        return response()->json([
            'outcome' => 'locked',
            'message' => self::MESSAGE,
            'locked_until' => $payment->card_locked_until?->toIso8601String(),
        ], 423);
    }

    /**
     * Admin release: clears the lock and starts a fresh count.
     */
    public function reset(Payment $payment): void
    {
        $payment->forceFill([
            'card_locked_until' => null,
            'card_attempts_since' => now(),
        ])->save();
    }

    private function applies(Payment $payment): bool
    {
        return in_array($payment->provider, [PaymentProvider::Mpgs, PaymentProvider::Authorizenet], true);
    }

    private function failedCount(Payment $payment): int
    {
        $query = $payment->provider === PaymentProvider::Mpgs
            ? $payment->mpgsChargeAttempts()->where('status', 'declined')
            : $payment->authorizenetChargeAttempts()->whereIn('status', self::ANET_FAILED);

        if ($payment->card_attempts_since !== null) {
            $query->where('created_at', '>', $payment->card_attempts_since);
        }

        return $query->count();
    }
}
