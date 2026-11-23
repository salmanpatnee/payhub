<?php

namespace App\Services\PaymentAttempts\Extractors;

use App\Models\Payment;
use App\Services\PaymentAttempts\AttemptData;
use App\Services\PaymentAttempts\AttemptExtractor;
use App\Services\Whop\WhopAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Payload: the decoded Whop webhook body. Context: webhook_id.
 * Failure field names are unconfirmed against a live body, so every read is defensive.
 */
class WhopAttemptExtractor implements AttemptExtractor
{
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData
    {
        $event = (string) ($payload['type'] ?? $payload['event'] ?? '');
        $status = match ($event) {
            'payment.succeeded' => 'succeeded',
            'payment.failed' => 'failed',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $subtotal = $data['subtotal'] ?? null;
        $amountText = is_array($subtotal) ? ($subtotal['amount'] ?? null) : $subtotal;
        $amount = is_string($amountText) || is_int($amountText) || is_float($amountText)
            ? WhopAmount::decimalToCents($amountText)
            : null;
        $currency = $data['currency'] ?? (is_array($subtotal) ? ($subtotal['currency'] ?? null) : null);
        $card = Arr::get($data, 'card');
        $card = is_array($card) ? $card : [];
        $created = $data['created_at'] ?? null;

        try {
            $occurred = match (true) {
                is_string($created) => CarbonImmutable::parse($created),
                is_int($created) => CarbonImmutable::createFromTimestamp($created),
                default => null,
            };
        } catch (\Throwable) {
            $occurred = null;
        }

        $code = $data['failure_code'] ?? null;
        $message = $data['failure_message'] ?? null;
        $webhookId = $context['webhook_id'] ?? '';

        return new AttemptData(
            event: $event,
            status: $status,
            eventKey: $webhookId !== '' ? (string) $webhookId : null,
            reference: is_string($data['id'] ?? null) ? $data['id'] : null,
            errorCode: is_string($code) ? $code : null,
            reason: is_string($message) ? $message : null,
            amount: $amount,
            currency: is_string($currency) ? $currency : null,
            amountReported: $amount !== null,
            response: array_filter([
                'id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
                'failure_code' => $code,
                'failure_message' => $message,
                'substatus' => $data['substatus'] ?? null,
                'payment_method_type' => $data['payment_method_type'] ?? null,
                'card_brand' => $card['brand'] ?? ($data['card_brand'] ?? null),
                'card_last4' => $card['last4'] ?? ($data['card_last4'] ?? null),
            ], fn ($v) => $v !== null),
            occurredAt: $occurred,
        );
    }
}
