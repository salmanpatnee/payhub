<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Worldpay\WorldpayChargeResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one Worldpay lookup for a payment (spec 0014, AC-8). Dispatched by the webhook controller;
 * the resolver alone decides what the status becomes.
 */
class ResolveWorldpayPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $paymentId,
        public readonly string $source = WorldpayChargeResolver::SOURCE_WEBHOOK,
    ) {}

    public function handle(WorldpayChargeResolver $resolver): void
    {
        $payment = Payment::find($this->paymentId);

        if ($payment !== null) {
            $resolver->resolve($payment, $this->source);
        }
    }
}
