<?php

namespace App\Services\Authorizenet;

use App\Models\Payment;

/**
 * Invoice number and amount helpers (spec 0006). The invoice number is the only
 * link between a gateway transaction and a PayHub payment, so one helper builds
 * it for the token request, the lookup and the webhook.
 */
class AuthorizenetInvoice
{
    /**
     * 6 digit zero padded payment id + first 8 hex characters of the payment UUID.
     * Authorize.net allows 20 characters at most.
     */
    public static function for(Payment $payment): string
    {
        return str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)
            .substr(str_replace('-', '', (string) $payment->uuid), 0, 8);
    }

    /** Integer cents to the decimal string the gateway wants, with integer math only. */
    public static function centsToDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** A gateway JSON float or string such as 12.34 to integer cents, without float noise. */
    public static function toCents(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round(((float) (string) $value) * 100);
    }
}
