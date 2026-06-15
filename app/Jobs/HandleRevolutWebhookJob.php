<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleRevolutWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly int $revolutAccountId,
        public readonly string $eventType,
        public readonly string $orderId,
    ) {}

    public function handle(): void
    {
        // Status guards mirror the Stripe job:
        // ORDER_COMPLETED: pending→completed (first payment) and failed→completed (retry after decline).
        // ORDER_PAYMENT_FAILED: only pending→failed; already-failed is a no-op.
        // completed is excluded from both guards — it is always terminal.
        $updated = match ($this->eventType) {
            'ORDER_COMPLETED' => Payment::where('revolut_order_id', $this->orderId)
                ->where('revolut_account_id', $this->revolutAccountId)
                ->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'completed', 'paid_at' => now()]),
            'ORDER_PAYMENT_FAILED' => Payment::where('revolut_order_id', $this->orderId)
                ->where('revolut_account_id', $this->revolutAccountId)
                ->where('status', 'pending')
                ->update(['status' => 'failed']),
            default => 0,
        };

        // Notify only when the DB write confirmed new paid state (idempotent).
        if ($updated > 0 && $this->eventType === 'ORDER_COMPLETED') {
            $payment = Payment::with(['brand', 'revolutAccount'])
                ->where('revolut_order_id', $this->orderId)
                ->first();

            if ($payment) {
                SendPaymentNotification::dispatch($payment);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleRevolutWebhookJob failed', [
            'revolut_account_id' => $this->revolutAccountId,
            'event_type' => $this->eventType,
            'order_id' => $this->orderId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
