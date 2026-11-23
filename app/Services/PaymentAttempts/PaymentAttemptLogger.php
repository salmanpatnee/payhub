<?php

namespace App\Services\PaymentAttempts;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sentry\State\Scope;

/**
 * Writes one row per verified provider outcome to `payment_attempts` (spec 0016).
 * Diagnostic only: it never changes a payment status and never throws (AC-2).
 */
class PaymentAttemptLogger
{
    public function __construct(private readonly AttemptPayloadSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function record(PaymentProvider $provider, ?Payment $payment, array $payload, array $context = []): ?PaymentAttempt
    {
        try {
            if ($payment === null || $payment->trashed()) {
                return null;
            }

            $class = config('payment_attempts.extractors.'.$provider->value);

            if (! is_string($class)) {
                return null;
            }

            /** @var AttemptExtractor $extractor */
            $extractor = app($class);
            $data = $extractor->extract($payload, $context, $payment);

            if ($data === null) {
                return null;
            }

            return $this->store($provider, $payment, $data);
        } catch (\Throwable $e) {
            $this->reportFailure($provider, $context['event'] ?? $context['type'] ?? 'unknown', $e);

            return null;
        }
    }

    private function store(PaymentProvider $provider, Payment $payment, AttemptData $data): PaymentAttempt
    {
        $attributes = $this->attributes($provider, $payment, $data);

        for ($try = 0; $try < 2; $try++) {
            try {
                return $this->insertInTransaction($attributes, $provider, $payment);
            } catch (UniqueConstraintViolationException $e) {
                // The failed transaction is rolled back; look outside it.
                if ($attributes['event_key'] !== null) {
                    $existing = PaymentAttempt::where('provider', $provider->value)
                        ->where('event_key', $attributes['event_key'])
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }
                }

                // A different event took the same sequence number: retry once with a fresh one.
                if ($try === 1) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('Unreachable');
    }

    /**
     * One locked transaction: reuse the row for a repeated event key, else take the next sequence.
     * Protected so a test can simulate a parallel writer winning the race (a unique key violation).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function insertInTransaction(array $attributes, PaymentProvider $provider, Payment $payment): PaymentAttempt
    {
        return DB::transaction(function () use ($attributes, $provider, $payment): PaymentAttempt {
            // Serialises writers per payment on MySQL (SQLite ignores the lock; the unique index covers it).
            Payment::withTrashed()->whereKey($payment->id)->lockForUpdate()->first();

            if ($attributes['event_key'] !== null) {
                $existing = PaymentAttempt::where('provider', $provider->value)
                    ->where('event_key', $attributes['event_key'])
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $sequence = ((int) PaymentAttempt::where('payment_id', $payment->id)->max('sequence')) + 1;

            return PaymentAttempt::create($attributes + ['sequence' => $sequence]);
        });
    }

    /** @return array<string, mixed> */
    private function attributes(PaymentProvider $provider, Payment $payment, AttemptData $data): array
    {
        $amount = $data->amount;
        $currency = $data->currency;
        $reported = $data->amountReported && $amount !== null;

        if ($amount === null) {
            $amount = (int) $payment->amount;
            $reported = false;
        }

        if ($currency === null || $currency === '') {
            $currency = $payment->currency;
        }

        return [
            'payment_id' => $payment->id,
            'provider' => $provider->value,
            'event_key' => $data->eventKey !== null ? mb_substr($data->eventKey, 0, 191) : null,
            'event' => mb_substr($data->event, 0, 100),
            'status' => $data->status,
            'amount' => $amount,
            'currency' => $currency !== null ? strtolower(mb_substr((string) $currency, 0, 3)) : null,
            'amount_reported' => $reported,
            'reference' => $this->sanitizer->text($data->reference, 191),
            'error_code' => $this->sanitizer->text($data->errorCode, 100),
            'reason' => $this->sanitizer->text($data->reason, 500),
            'source' => mb_substr($data->source, 0, 20),
            'request' => $this->sanitizer->sanitize($data->request),
            'response' => $this->sanitizer->sanitize($data->response),
            // Extractors build UTC times; Laravel stores the clock time as it is and reads it back
            // in the app timezone, so convert first or the page shows the wrong hour.
            'occurred_at' => $data->occurredAt?->setTimezone(config('app.timezone')) ?? now(),
        ];
    }

    private function reportFailure(PaymentProvider $provider, string $event, \Throwable $e): void
    {
        try {
            Log::warning('Payment attempt log failed', [
                'provider' => $provider->value,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            \Sentry\withScope(function (Scope $scope) use ($provider, $event, $e): void {
                $scope->setTag('payment.provider', $provider->value);
                $scope->setFingerprint(['payment-attempt-log', $provider->value, $event]);
                $scope->setContext('payment_attempt_log', ['event' => $event, 'exception' => $e::class]);
                \Sentry\captureMessage('Payment attempt log failed ('.$provider->value.')');
            });
        } catch (\Throwable) {
            // Logging the failure must never break the caller either.
        }
    }
}
