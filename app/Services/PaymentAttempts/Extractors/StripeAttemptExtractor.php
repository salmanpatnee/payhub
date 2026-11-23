<?php

namespace App\Services\PaymentAttempts\Extractors;

use App\Models\Payment;
use App\Services\PaymentAttempts\AttemptData;
use App\Services\PaymentAttempts\AttemptExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/** Payload: the PaymentIntent. Context: event_id, created (epoch seconds), type. */
class StripeAttemptExtractor implements AttemptExtractor
{
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData
    {
        $type = (string) ($context['type'] ?? '');
        $status = match ($type) {
            'payment_intent.succeeded' => 'succeeded',
            'payment_intent.payment_failed' => 'failed',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $error = is_array($payload['last_payment_error'] ?? null) ? $payload['last_payment_error'] : [];
        $card = Arr::get($error, 'payment_method.card');
        $latestCharge = $payload['latest_charge'] ?? null;

        $response = [
            'status' => $payload['status'] ?? null,
            'latest_charge' => is_string($latestCharge) ? $latestCharge : null,
        ];

        if ($error !== []) {
            $response['last_payment_error'] = array_filter([
                'type' => $error['type'] ?? null,
                'code' => $error['code'] ?? null,
                'decline_code' => $error['decline_code'] ?? null,
                'message' => $error['message'] ?? null,
                'payment_method_type' => Arr::get($error, 'payment_method.type'),
                'card' => is_array($card) ? Arr::only($card, ['brand', 'last4', 'funding', 'country']) : null,
            ], fn ($v) => $v !== null && $v !== []);
        }

        $created = $context['created'] ?? null;
        $hasAmount = isset($payload['amount']) && is_numeric($payload['amount']);

        return new AttemptData(
            event: $type,
            status: $status,
            eventKey: isset($context['event_id']) ? (string) $context['event_id'] : null,
            reference: is_string($payload['id'] ?? null) ? $payload['id'] : null,
            errorCode: $error['decline_code'] ?? $error['code'] ?? null,
            reason: $error['message'] ?? null,
            amount: $hasAmount ? (int) $payload['amount'] : null,
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : null,
            amountReported: $hasAmount,
            response: array_filter($response, fn ($v) => $v !== null),
            occurredAt: is_numeric($created) ? CarbonImmutable::createFromTimestamp((int) $created) : null,
        );
    }
}
