<?php

namespace App\Services\PaymentAttempts\Extractors;

use App\Models\Payment;
use App\Services\PaymentAttempts\AttemptData;
use App\Services\PaymentAttempts\AttemptExtractor;
use Carbon\CarbonImmutable;

/**
 * Payload: the decoded webhook body (`event`, `order_id`). Context: timestamp_ms (signed header).
 * Revolut carries no reason or amount.
 */
class RevolutAttemptExtractor implements AttemptExtractor
{
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData
    {
        $event = (string) ($payload['event'] ?? $context['event'] ?? '');
        $orderId = $payload['order_id'] ?? null;

        $status = match ($event) {
            'ORDER_COMPLETED' => 'succeeded',
            'ORDER_PAYMENT_FAILED' => 'failed',
            default => null,
        };

        if ($status === null || ! is_string($orderId) || $orderId === '') {
            return null;
        }

        return new AttemptData(
            event: $event,
            status: $status,
            eventKey: $orderId.':'.$event,
            reference: $orderId,
            response: ['event' => $event, 'order_id' => $orderId],
            occurredAt: is_numeric($context['timestamp_ms'] ?? null)
                ? CarbonImmutable::createFromTimestampMs((int) $context['timestamp_ms'])
                : null,
        );
    }
}
