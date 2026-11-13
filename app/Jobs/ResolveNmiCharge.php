<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Nmi\NmiChargeResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Settles a charge whose outcome was unclear (spec 0012, AC-10): looks the payment's order id up at
 * NMI and applies what it finds through the resolver. Read only; it never creates a charge.
 */
class ResolveNmiCharge implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $paymentId) {}

    public function handle(NmiChargeResolver $resolver): void
    {
        $payment = Payment::find($this->paymentId);

        if ($payment === null || $payment->status !== 'pending') {
            return;
        }

        $resolver->resolve($payment);
    }
}
