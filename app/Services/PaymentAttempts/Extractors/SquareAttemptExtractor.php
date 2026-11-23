<?php

namespace App\Services\PaymentAttempts\Extractors;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttempts\AttemptData;
use App\Services\PaymentAttempts\AttemptExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Payload: the Square `payment` object. Context: event_id, created_at.
 * Or, with context `source = call`, a declined CreatePayment call: payload `errors` and `http_status`.
 */
class SquareAttemptExtractor implements AttemptExtractor
{
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData
    {
        if (($context['source'] ?? null) === 'call') {
            return $this->declinedCall($payload);
        }

        $squareStatus = (string) ($payload['status'] ?? '');
        $status = match ($squareStatus) {
            'COMPLETED' => 'succeeded',
            'FAILED' => 'failed',
            'CANCELED' => 'cancelled',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $reference = is_string($payload['id'] ?? null) ? $payload['id'] : null;

        // Later payment.updated events (a refund, for example) repeat the same status: skip them.
        $last = PaymentAttempt::where('payment_id', $payment->id)
            ->where('provider', 'square')
            ->when($reference !== null, fn ($q) => $q->where('reference', $reference))
            ->orderByDesc('sequence')
            ->first();

        if ($last !== null && $last->event === $squareStatus) {
            return null;
        }

        $errors = Arr::get($payload, 'card_details.errors');
        $errors = is_array($errors) ? $errors : [];
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $money = is_array($payload['amount_money'] ?? null) ? $payload['amount_money'] : [];
        $card = Arr::get($payload, 'card_details.card');
        $card = is_array($card) ? $card : [];
        $createdAt = $context['created_at'] ?? null;
        $hasAmount = isset($money['amount']) && is_numeric($money['amount']);

        try {
            $occurred = is_string($createdAt) ? CarbonImmutable::parse($createdAt) : null;
        } catch (\Throwable) {
            $occurred = null;
        }

        return new AttemptData(
            event: $squareStatus,
            status: $status,
            eventKey: isset($context['event_id']) ? (string) $context['event_id'] : null,
            reference: $reference,
            errorCode: $first['code'] ?? null,
            reason: $first['detail'] ?? null,
            amount: $hasAmount ? (int) $money['amount'] : null,
            currency: is_string($money['currency'] ?? null) ? $money['currency'] : null,
            amountReported: $hasAmount,
            response: array_filter([
                'status' => $squareStatus,
                'card_status' => Arr::get($payload, 'card_details.status'),
                'entry_method' => Arr::get($payload, 'card_details.entry_method'),
                'card_brand' => $card['card_brand'] ?? null,
                'last_4' => $card['last_4'] ?? null,
                'card_type' => $card['card_type'] ?? null,
                'errors' => array_map(
                    fn ($e) => is_array($e) ? Arr::only($e, ['code', 'category', 'detail']) : [],
                    $errors,
                ),
            ], fn ($v) => $v !== null && $v !== []),
            occurredAt: $occurred,
        );
    }

    /**
     * A card Square refused outright answers with an error and creates no payment, so no webhook follows.
     * No event key: every customer attempt is its own row. Amount and currency come from the payment.
     *
     * @param  array<string, mixed>  $payload
     */
    private function declinedCall(array $payload): AttemptData
    {
        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];

        return new AttemptData(
            event: 'CREATE_PAYMENT_DECLINED',
            status: 'failed',
            errorCode: isset($first['code']) ? (string) $first['code'] : null,
            reason: isset($first['detail']) ? (string) $first['detail'] : null,
            response: array_filter([
                'http_status' => $payload['http_status'] ?? null,
                'errors' => array_map(
                    fn ($e) => is_array($e) ? Arr::only($e, ['code', 'category', 'detail']) : [],
                    $errors,
                ),
            ], fn ($v) => $v !== null && $v !== []),
            source: 'call',
        );
    }
}
