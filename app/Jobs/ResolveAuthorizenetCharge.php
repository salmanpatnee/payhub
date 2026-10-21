<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Authorizenet\AuthorizenetInvoiceLookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Settles a charge whose outcome was unclear (spec 0007, AC-9): looks the
 * payment's invoice number up at Authorize.net and applies what it finds through
 * the shared resolver. Read only; it never creates a charge.
 */
class ResolveAuthorizenetCharge implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $paymentId) {}

    public function handle(AuthorizenetInvoiceLookup $lookup): void
    {
        $payment = Payment::with('authorizenetAccount')->find($this->paymentId);

        if ($payment === null || $payment->status !== 'pending') {
            return;
        }

        $lookup->lookup($payment);
    }
}
