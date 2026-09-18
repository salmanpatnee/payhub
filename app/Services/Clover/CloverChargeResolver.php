<?php

namespace App\Services\Clover;

use App\Jobs\SendPaymentNotification;
use App\Models\CloverChargeAttempt;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Applies a classified Clover charge response to its attempt and payment.
 * Shared by the synchronous charge handler (ClientPaymentController::chargeClover)
 * and the resolver job (ResolveCloverChargeAttempt) so both paths apply the
 * exact same guarded-update shape — the same two-guard pattern
 * HandleSquareWebhookJob/HandleStripeWebhookJob already use (spec 0002, AC-6).
 */
class CloverChargeResolver
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function resolve(CloverChargeAttempt $attempt, string $classification, array $body): void
    {
        // A successful charge carries its id at the top level; a declined one
        // carries it under error.charge instead (confirmed live, 2026-09-18) —
        // no top-level "id" at all on a decline.
        $chargeId = $body['id'] ?? $body['error']['charge'] ?? $attempt->clover_charge_id;
        $chargeId = $chargeId !== null ? (string) $chargeId : null;

        // "order" is a separate identifier from the charge id — confirmed
        // live, 2026-09-18, per the engineer's own check against Clover's
        // merchant dashboard: it's what's actually searchable there, unlike
        // the charge id. Only present on a GET read, never on the original
        // POST /v1/charges response.
        $orderId = isset($body['order']) ? (string) $body['order'] : null;

        match ($classification) {
            'approved' => $this->approve($attempt, $chargeId, $orderId),
            'declined' => $this->decline($attempt, $chargeId, $body),
            'hard_error' => $this->hardError($attempt, $chargeId, $body),
            default => $this->stayUnknown($attempt, $chargeId),
        };
    }

    private function approve(CloverChargeAttempt $attempt, ?string $chargeId, ?string $orderId): void
    {
        // clover_charge_id (on the attempt) always stays the real charge id —
        // the resolver job looks charges up by it. clover_payment_id (on the
        // payment, shown as "Provider Reference" in the CSV export/dashboard)
        // prefers the order id when available, falling back to the charge id
        // when it isn't (the fast synchronous path — see chargeClover()).
        $updated = Payment::whereKey($attempt->payment_id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'completed',
                'paid_at' => now(),
                'clover_payment_id' => $orderId ?? $chargeId,
            ]);

        $attempt->update([
            'status' => 'approved',
            'clover_charge_id' => $chargeId,
            'last_checked_at' => now(),
        ]);

        // $updated > 0 gate: only the write that actually flipped the payment
        // dispatches the notification — never twice for the same completion.
        if ($updated > 0) {
            $payment = Payment::with(['brand'])->find($attempt->payment_id);

            if ($payment) {
                SendPaymentNotification::dispatch($payment);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function decline(CloverChargeAttempt $attempt, ?string $chargeId, array $body): void
    {
        // where('status', 'pending'): never overwrites an already-completed payment.
        Payment::whereKey($attempt->payment_id)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        // Clover's error responses nest decline detail under "error", and the
        // decline code itself is camelCase (declineCode), not the snake_case
        // decline_code Clover's own field-reference docs use (confirmed live,
        // 2026-09-18) — check both, falling back to the generic message/error
        // for defensiveness against a shape we haven't seen.
        $declineReason = $body['error']['declineCode']
            ?? $body['error']['decline_code']
            ?? $body['error']['message']
            ?? $body['declineCode']
            ?? $body['decline_code']
            ?? $body['error']
            ?? null;

        $attempt->update([
            'status' => 'declined',
            'clover_charge_id' => $chargeId,
            // Operators only — never sent to the client (see the controller's
            // fixed, outcome-keyed client-facing message).
            'decline_reason' => is_array($declineReason) ? json_encode($declineReason) : $declineReason,
            'last_checked_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function hardError(CloverChargeAttempt $attempt, ?string $chargeId, array $body): void
    {
        Log::error('Clover charge hard error — request itself was invalid or unauthorized', [
            'attempt_id' => $attempt->id,
            'payment_id' => $attempt->payment_id,
            'body' => $body,
        ]);

        // Payment untouched: retrying an invalid request produces the same
        // invalid request (spec 0002's classification table).
        $attempt->update([
            'status' => 'hard_error',
            'clover_charge_id' => $chargeId,
            'last_checked_at' => now(),
        ]);
    }

    private function stayUnknown(CloverChargeAttempt $attempt, ?string $chargeId): void
    {
        $attempt->update([
            'clover_charge_id' => $chargeId,
            'attempts_count' => $attempt->attempts_count + 1,
            'last_checked_at' => now(),
        ]);
    }
}
