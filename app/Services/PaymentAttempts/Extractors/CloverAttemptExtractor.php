<?php

namespace App\Services\PaymentAttempts\Extractors;

use App\Models\Payment;
use App\Services\PaymentAttempts\AttemptData;
use App\Services\PaymentAttempts\AttemptExtractor;
use Illuminate\Support\Arr;

/**
 * Payload: Clover's charge body (its reply to the charge call, or a read back of the charge).
 * Context: classification (approved, declined, hard_error, unknown) and attempt_id.
 * Clover has no webhook, so rows come from CloverChargeResolver, the one place every status is written.
 */
class CloverAttemptExtractor implements AttemptExtractor
{
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData
    {
        $classification = (string) ($context['classification'] ?? '');
        $status = match ($classification) {
            'approved' => 'succeeded',
            'declined', 'hard_error' => 'failed',
            default => null, // unknown: the later resolution of the same attempt is logged instead
        };

        if ($status === null || ! isset($context['attempt_id'])) {
            return null;
        }

        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $chargeId = $payload['id'] ?? $error['charge'] ?? null;
        $hasAmount = isset($payload['amount']) && is_numeric($payload['amount']);

        $errorCode = $error['declineCode'] ?? $error['decline_code'] ?? $payload['declineCode'] ?? $payload['decline_code'] ?? $error['code'] ?? null;
        $reason = $error['message'] ?? null;

        return new AttemptData(
            event: $classification,
            status: $status,
            eventKey: 'clover:'.$context['attempt_id'],
            reference: is_string($chargeId) || is_int($chargeId) ? (string) $chargeId : null,
            errorCode: is_string($errorCode) ? $errorCode : null,
            reason: is_string($reason) ? $reason : null,
            amount: $hasAmount ? (int) $payload['amount'] : null,
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : null,
            amountReported: $hasAmount,
            response: array_filter([
                'paid' => $payload['paid'] ?? null,
                'captured' => $payload['captured'] ?? null,
                'status' => $payload['status'] ?? null,
                'order' => isset($payload['order']) ? (string) $payload['order'] : null,
                'card_brand' => Arr::get($payload, 'source.brand'),
                'last_4' => Arr::get($payload, 'source.last4'),
                'error' => $error === [] ? null : Arr::only($error, ['type', 'code', 'declineCode', 'decline_code', 'message']),
            ], fn ($v) => $v !== null && $v !== []),
            source: 'call',
        );
    }
}
