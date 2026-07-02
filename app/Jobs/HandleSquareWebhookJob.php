<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleSquareWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly int $squareAccountId,
        public readonly ?string $squarePaymentId,
        public readonly ?string $squareStatus,
    ) {}

    public function handle(): void
    {
        // Map Square payment.status → PayHub status. Each branch has its own status guard
        // for idempotency and retry support (mirror of HandleStripeWebhookJob).
        // COMPLETED: allows pending→completed (first payment) and failed→completed (retry after decline).
        // FAILED: only pending→failed; already-failed is a no-op.
        // CANCELED: pending/failed → cancelled; completed is terminal and never touched.
        $updated = match ($this->squareStatus) {
            'COMPLETED' => Payment::where('square_payment_id', $this->squarePaymentId)
                ->where('square_account_id', $this->squareAccountId)
                ->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'completed', 'paid_at' => now()]),
            'FAILED' => Payment::where('square_payment_id', $this->squarePaymentId)
                ->where('square_account_id', $this->squareAccountId)
                ->where('status', 'pending')
                ->update(['status' => 'failed']),
            'CANCELED' => Payment::where('square_payment_id', $this->squarePaymentId)
                ->where('square_account_id', $this->squareAccountId)
                ->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'cancelled']),
            default => 0,
        };

        // Dispatch email notification only for COMPLETED events where the DB write confirmed
        // new state (idempotency: $updated === 0 means already terminal, skip notification).
        if ($updated > 0 && $this->squareStatus === 'COMPLETED') {
            $payment = Payment::with(['brand', 'squareAccount'])
                ->where('square_payment_id', $this->squarePaymentId)
                ->first();

            if ($payment) {
                SendPaymentNotification::dispatch($payment);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleSquareWebhookJob failed', [
            'square_account_id' => $this->squareAccountId,
            'square_payment_id' => $this->squarePaymentId,
            'square_status' => $this->squareStatus,
            'error' => $exception?->getMessage(),
        ]);
    }
}
