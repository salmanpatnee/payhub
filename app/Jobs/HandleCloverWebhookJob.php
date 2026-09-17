<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleCloverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly int $paymentId,
        public readonly string $status,
        public readonly ?string $cloverPaymentId,
    ) {}

    public function handle(): void
    {
        // Clover's webhook carries a real HMAC signature, verified in
        // CloverWebhookController before this job is ever dispatched — unlike
        // Viva's unsigned webhook, the payload is trusted directly here, the
        // same way Stripe's/Revolut's/Square's already are (no re-fetch).
        // Unlike Square/Viva, a Clover payment's 'failed' status is ALSO
        // terminal (AC-6/AC-7, Key invariants) — there is no failed→completed
        // retry transition, because a retry after a decline gets a brand new
        // checkout session (AC-4) and therefore its own Payment attempt, not
        // a status flip on the same row. The controller already re-checks
        // this guard immediately before dispatch; only('pending') here keeps
        // this job's own semantics honest even if called any other way.
        $updated = match ($this->status) {
            'APPROVED' => Payment::where('id', $this->paymentId)
                ->where('status', 'pending')
                ->update(['status' => 'completed', 'paid_at' => now(), 'clover_payment_id' => $this->cloverPaymentId]),
            'DECLINED' => Payment::where('id', $this->paymentId)
                ->where('status', 'pending')
                ->update(['status' => 'failed', 'clover_payment_id' => $this->cloverPaymentId]),
            default => 0,
        };

        if ($updated > 0 && $this->status === 'APPROVED') {
            $payment = Payment::with(['brand', 'cloverAccount'])->find($this->paymentId);

            if ($payment) {
                SendPaymentNotification::dispatch($payment);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleCloverWebhookJob failed', [
            'payment_id' => $this->paymentId,
            'status' => $this->status,
            'clover_payment_id' => $this->cloverPaymentId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
