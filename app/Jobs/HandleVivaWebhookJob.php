<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleVivaWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly int $vivaAccountId,
        public readonly string $orderCode,
        public readonly ?string $transactionId,
    ) {}

    public function handle(): void
    {
        // Only TransactionPaymentCreated (payment succeeded) is currently routed
        // here by VivaWebhookController — see its HANDLED_EVENT_TYPE_IDS comment.
        // Allows pending→completed (first payment) and failed→completed (retry).
        $updated = Payment::where('viva_order_code', $this->orderCode)
            ->where('viva_account_id', $this->vivaAccountId)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'completed',
                'paid_at' => now(),
                'viva_transaction_id' => $this->transactionId,
            ]);

        // Dispatch email notification only when the DB write confirmed new state
        // (idempotency: $updated === 0 means already terminal, skip notification).
        if ($updated > 0) {
            $payment = Payment::with(['brand', 'vivaAccount'])
                ->where('viva_order_code', $this->orderCode)
                ->first();

            if ($payment) {
                SendPaymentNotification::dispatch($payment);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleVivaWebhookJob failed', [
            'viva_account_id' => $this->vivaAccountId,
            'viva_order_code' => $this->orderCode,
            'transaction_id' => $this->transactionId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
