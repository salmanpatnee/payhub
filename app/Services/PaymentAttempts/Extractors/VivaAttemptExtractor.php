<?php

namespace App\Services\PaymentAttempts\Extractors;

use App\Models\Payment;
use App\Services\PaymentAttempts\AttemptData;
use App\Services\PaymentAttempts\AttemptExtractor;
use Carbon\CarbonImmutable;

/**
 * Payload: the transaction re fetched from Viva's API (never the unsigned webhook).
 * Context: event_key, event_type_id, transaction_id. Status letters other than F are unconfirmed (spec 0016).
 */
class VivaAttemptExtractor implements AttemptExtractor
{
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData
    {
        $statusId = isset($payload['statusId']) ? (string) $payload['statusId'] : '';
        $status = match ($statusId) {
            'F' => 'succeeded',
            'E' => 'failed',
            'X' => 'cancelled',
            default => 'pending',
        };

        $currency = match ((string) ($payload['currencyCode'] ?? '')) {
            '826' => 'gbp',
            '840' => 'usd',
            default => null,
        };

        $amount = $currency !== null ? $this->minorUnits($payload['amount'] ?? null) : null;

        $reference = $payload['transactionId'] ?? $context['transaction_id'] ?? null;
        $time = $payload['insDate'] ?? null;

        try {
            $occurred = is_string($time) ? CarbonImmutable::parse($time) : null;
        } catch (\Throwable) {
            $occurred = null;
        }

        return new AttemptData(
            event: 'status_'.($statusId !== '' ? $statusId : 'unknown'),
            status: $status,
            eventKey: isset($context['event_key']) ? (string) $context['event_key'] : null,
            reference: is_string($reference) ? $reference : null,
            amount: $amount,
            currency: $currency,
            amountReported: $amount !== null,
            response: array_filter([
                'statusId' => $statusId !== '' ? $statusId : null,
                'orderCode' => isset($payload['orderCode']) ? (string) $payload['orderCode'] : null,
                'eventTypeId' => $context['event_type_id'] ?? null,
                'transactionTypeId' => $payload['transactionTypeId'] ?? null,
            ], fn ($v) => $v !== null),
            occurredAt: $occurred,
        );
    }

    /** Viva sends major units (a number or numeric string); convert with string math, no float rounding. */
    private function minorUnits(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $text = is_float($value) ? number_format($value, 2, '.', '') : trim((string) $value);

        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $text, $m)) {
            return null;
        }

        return (int) ($m[1].str_pad(substr($m[2] ?? '', 0, 2), 2, '0'));
    }
}
