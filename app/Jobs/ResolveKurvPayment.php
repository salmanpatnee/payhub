<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Kurv\KurvChargeResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one Kurv lookup for a payment (spec 0010, AC-8). Dispatched by the callback
 * controller; the resolver alone decides what the status becomes.
 */
class ResolveKurvPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $paymentId,
        public readonly string $source = KurvChargeResolver::SOURCE_CALLBACK,
    ) {}

    public function handle(KurvChargeResolver $resolver): void
    {
        $payment = Payment::find($this->paymentId);

        if ($payment !== null) {
            $resolver->resolve($payment, $this->source);
        }
    }
}
