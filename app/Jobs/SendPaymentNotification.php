<?php

namespace App\Jobs;

use App\Mail\PaymentSucceeded;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly Payment $payment,
    ) {}

    public function handle(): void
    {
        $admins = User::role('admin')->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->queue(new PaymentSucceeded($this->payment));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('SendPaymentNotification failed', [
            'payment_uuid' => $this->payment->uuid,
            'error' => $exception?->getMessage(),
            // NEVER log stripe_payment_intent_id or client_secret (CLAUDE.md rule)
        ]);
    }
}
