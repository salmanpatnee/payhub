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
        $payment = Payment::where('stripe_payment_intent_id', $piId)->first();

        if (! $payment || in_array($payment->status, ['completed', 'failed'])) {
            return; // idempotency gate 2
        }

        match ($this->eventType) {
            'payment_intent.succeeded' => $payment->update(['status' => 'completed', 'paid_at' => now()]),
            'payment_intent.payment_failed' => $payment->update(['status' => 'failed']),
            default => null,
        };
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
