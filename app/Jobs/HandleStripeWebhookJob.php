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

        // Atomic update: WHERE status = 'pending' acts as an idempotency guard.
        // Adding stripe_account_id scopes the update to the correct account.
        // If $updated === 0, the payment is already in a terminal state (or not found) — no-op.
        $updated = Payment::where('stripe_payment_intent_id', $piId)
            ->where('stripe_account_id', $this->stripeAccountId)
            ->where('status', 'pending')
            ->update(match ($this->eventType) {
                'payment_intent.succeeded' => ['status' => 'completed', 'paid_at' => now()],
                'payment_intent.payment_failed' => ['status' => 'failed'],
                default => [],
            });

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
    }
}
