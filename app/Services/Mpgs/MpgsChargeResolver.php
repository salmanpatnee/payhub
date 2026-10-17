<?php

namespace App\Services\Mpgs;

use App\Jobs\SendPaymentNotification;
use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * The one place a classified MPGS result is applied to its attempt and payment
 * (spec 0005, AC-8). Shared by the synchronous pay path, the webhook job and
 * the resolver job so all three use the same two-guard update the Stripe,
 * Square and Clover handlers use: whereIn('status', ['pending', 'failed']) for
 * completion, where('status', 'pending') for a decline.
 *
 * Every final state also clears the attempt's encrypted session id.
 */
class MpgsChargeResolver
{
    /**
     * @param  array<string, mixed>  $body  the gateway body the classification came from
     */
    public function resolve(MpgsChargeAttempt $attempt, string $classification, array $body): void
    {
        match ($classification) {
            'approved' => $this->approve($attempt, $body),
            'declined' => $this->decline($attempt, $body),
            'hard_error' => $this->hardError($attempt, $body),
            default => $this->stayUnknown($attempt),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function approve(MpgsChargeAttempt $attempt, array $body): void
    {
        $updated = Payment::whereKey($attempt->payment_id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'completed',
                'paid_at' => now(),
                'mpgs_order_id' => $attempt->order_id,
            ]);

        $attempt->update([
            'status' => 'approved',
            'session_id' => null,
            'gateway_code' => $this->gatewayCode($body),
            'receipt' => $this->receipt($body),
            'last_checked_at' => now(),
        ]);

        // $updated > 0 gate: only the write that actually flipped the payment
        // dispatches the notification, never twice for the same completion.
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
    private function decline(MpgsChargeAttempt $attempt, array $body): void
    {
        // where('status', 'pending'): never overwrites an already completed payment.
        Payment::whereKey($attempt->payment_id)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        $attempt->update([
            'status' => 'declined',
            'session_id' => null,
            'gateway_code' => $this->gatewayCode($body),
            // Operators only, never sent to the client.
            'decline_reason' => $this->declineReason($body),
            'last_checked_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function hardError(MpgsChargeAttempt $attempt, array $body): void
    {
        // Log the gateway's error block only: it carries cause, explanation and
        // field, never the session id or credentials.
        Log::error('MPGS hard error: request itself was invalid or unauthorized', [
            'attempt_id' => $attempt->id,
            'payment_id' => $attempt->payment_id,
            'error' => $body['error'] ?? null,
        ]);

        // Payment untouched: retrying an invalid request produces the same one.
        $attempt->update([
            'status' => 'hard_error',
            'session_id' => null,
            'gateway_code' => $this->gatewayCode($body),
            'last_checked_at' => now(),
        ]);
    }

    private function stayUnknown(MpgsChargeAttempt $attempt): void
    {
        $attempt->update([
            'attempts_count' => $attempt->attempts_count + 1,
            'last_checked_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function gatewayCode(array $body): ?string
    {
        $code = $body['response']['gatewayCode'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function receipt(array $body): ?string
    {
        $receipt = $body['transaction']['receipt'] ?? null;

        return is_string($receipt) ? $receipt : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function declineReason(array $body): ?string
    {
        $reason = $body['response']['acquirerMessage']
            ?? $body['response']['gatewayCode']
            ?? $body['error']['explanation']
            ?? null;

        return is_string($reason) ? mb_substr($reason, 0, 255) : null;
    }
}
