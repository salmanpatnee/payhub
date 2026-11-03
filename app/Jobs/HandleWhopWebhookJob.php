<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\ProcessedWhopEvent;
use App\Services\Whop\WhopWebhookPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sentry\State\Scope;

/**
 * The only code allowed to set a Whop payment to `completed` (spec 0009, AC-10, AC-11).
 */
class HandleWhopWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly int $whopAccountId,
        public readonly string $webhookId,
        public readonly string $rawBody,
    ) {}

    public function handle(): void
    {
        $payload = WhopWebhookPayload::fromRawBody($this->rawBody);

        if ($payload === null) {
            Log::error('Whop webhook body is not JSON', ['webhook_id' => $this->webhookId, 'whop_account_id' => $this->whopAccountId]);

            return;
        }

        $eventType = $payload->eventType() ?? 'unknown';

        $completed = DB::transaction(function () use ($payload, $eventType): ?Payment {
            // Dedupe inside the same transaction as the status update: a failed job
            // rolls the row back, so Whop's retry is processed.
            $inserted = ProcessedWhopEvent::query()->insertOrIgnore([
                'webhook_id' => $this->webhookId,
                'event_type' => $eventType,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return null;
            }

            if ($eventType !== 'payment.succeeded') {
                // payment.failed, refunds, disputes and the rest: log only, no status change.
                Log::info('Whop webhook ignored', ['event_type' => $eventType, 'webhook_id' => $this->webhookId]);

                return null;
            }

            return $this->completePayment($payload);
        });

        if ($completed !== null) {
            SendPaymentNotification::dispatch($completed);
        }
    }

    private function completePayment(WhopWebhookPayload $payload): ?Payment
    {
        $uuid = $payload->paymentUuid();

        if ($uuid === null) {
            // For example a payment made from the Whop dashboard, not through PayHub.
            Log::info('Whop payment.succeeded has no payment_id metadata, ignored', ['webhook_id' => $this->webhookId]);

            return null;
        }

        $payment = Payment::where('uuid', $uuid)->lockForUpdate()->first();

        if ($payment === null) {
            Log::error('Whop payment.succeeded for an unknown PayHub payment', ['payment_uuid' => $uuid, 'webhook_id' => $this->webhookId]);

            return null;
        }

        $context = [
            'payment_id' => $payment->id,
            'whop_payment_id' => $payload->whopPaymentId(),
            'whop_account_id' => $this->whopAccountId,
            'webhook_id' => $this->webhookId,
        ];

        if ($payment->provider->value !== 'whop' || (int) $payment->whop_account_id !== $this->whopAccountId) {
            Log::error('Whop payment.succeeded belongs to another account or provider', $context);

            return null;
        }

        if ($payload->priceCents() !== (int) $payment->amount || $payload->currency() !== strtolower((string) $payment->currency)) {
            Log::error('Whop payment.succeeded amount or currency mismatch', $context + [
                'event_cents' => $payload->priceCents(),
                'event_currency' => $payload->currency(),
                'payment_cents' => (int) $payment->amount,
                'payment_currency' => $payment->currency,
            ]);

            return null;
        }

        if ($payment->status === 'cancelled') {
            Log::error('Whop payment.succeeded for a cancelled payment', $context);

            return null;
        }

        if ($payment->status === 'completed') {
            if ($payment->whop_payment_id !== null && $payment->whop_payment_id !== $payload->whopPaymentId()) {
                Log::error('Second Whop success with a different Whop payment id for a completed payment', $context + [
                    'recorded_whop_payment_id' => $payment->whop_payment_id,
                ]);
            }

            return null;
        }

        $updated = Payment::where('id', $payment->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'completed',
                'paid_at' => $payload->paidAt() ?? now(),
                'whop_payment_id' => $payload->whopPaymentId(),
            ]);

        if ($updated === 0) {
            return null;
        }

        Cache::forget('whop:pause:'.$payment->id);

        return $payment->fresh(['brand', 'whopAccount']);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleWhopWebhookJob failed', [
            'whop_account_id' => $this->whopAccountId,
            'webhook_id' => $this->webhookId,
            'error' => $exception?->getMessage(),
        ]);

        if ($exception === null) {
            return;
        }

        \Sentry\withScope(function (Scope $scope) use ($exception): void {
            $scope->setTag('payment.provider', 'whop');
            $scope->setContext('payment', [
                'whop_account_id' => $this->whopAccountId,
                'webhook_id' => $this->webhookId,
            ]);
            \Sentry\captureException($exception);
        });
    }
}
