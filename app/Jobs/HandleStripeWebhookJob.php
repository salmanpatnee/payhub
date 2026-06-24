<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleStripeWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly int $stripeAccountId,
        public readonly string $eventType,
        public readonly array $eventData,
    ) {}

    public function handle(): void
    {
        // eventData is $event->data->object->toArray() — a flat PaymentIntent array
        $piId = $this->eventData['id'] ?? null;

        // Each event type has its own status guard for idempotency and retry support.
        // succeeded: allows pending→completed (first payment) and failed→completed (retry after decline).
        // payment_failed: only pending→failed; already-failed is a no-op to prevent double-updates.
        // completed is excluded from both guards — it is always terminal.
        $updated = match ($this->eventType) {
            'payment_intent.succeeded' => Payment::where('stripe_payment_intent_id', $piId)
                ->where('stripe_account_id', $this->stripeAccountId)
                ->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'completed', 'paid_at' => now()]),
            'payment_intent.payment_failed' => Payment::where('stripe_payment_intent_id', $piId)
                ->where('stripe_account_id', $this->stripeAccountId)
                ->where('status', 'pending')
                ->update(['status' => 'failed']),
            default => 0,
        };

        // Dispatch email notification only for succeeded events where the DB write confirmed
        // new state (idempotency: $updated === 0 means already terminal, skip notification).
        if ($updated > 0 && $this->eventType === 'payment_intent.succeeded') {
            $payment = Payment::with(['brand', 'stripeAccount'])
                ->where('stripe_payment_intent_id', $piId)
                ->first();

            if ($payment) {
                SendPaymentNotification::dispatch($payment);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleStripeWebhookJob failed', [
            'stripe_account_id' => $this->stripeAccountId,
            'event_type' => $this->eventType,
            'pi_id' => $this->eventData['id'] ?? null,
            'error' => $exception?->getMessage(),
            // NEVER log full $this->eventData — may contain client_secret (CLAUDE.md rule)
        ]);

        if ($exception === null) {
            return;
        }

        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($exception): void {
            $scope->setTag('payment.provider', 'stripe');
            $scope->setTag('payment.event_type', $this->eventType);
            $scope->setContext('payment', [
                'stripe_account_id' => $this->stripeAccountId,
                'payment_intent_id' => $this->eventData['id'] ?? null,
                'event_type' => $this->eventType,
            ]);
            \Sentry\captureException($exception);
        });
    }
}
