<?php

namespace App\Services\Authorizenet;

use App\Models\Payment;

/**
 * Invoice number and amount helpers (spec 0006). The invoice number is the only
 * link between a gateway transaction and a PayHub payment, so one helper builds
 * it for the charge request, the lookup and the webhook.
 *
 * It is the payment's reference code ("AUTH-001020", or "001020" without a prefix)
 * because the invoice number is the only field the Authorize.net reports show.
 * Known limitation: it is built from the live account prefix, so editing an
 * account's prefix after a charge stops that payment from matching its transaction.
 */
class AuthorizenetInvoice
{
    /** The longest invoice number Authorize.net accepts. */
    public const MAX_LENGTH = 20;

    /**
     * The reference code without the "#" the no-prefix form carries. A payment with
     * no reference code yet falls back to the legacy value.
     */
    public static function for(Payment $payment): string
    {
        if ($payment->reference_code === null) {
            return self::legacy($payment);
        }

        return substr(ltrim($payment->formattedReferenceCode(), '#'), 0, self::MAX_LENGTH);
    }

    /**
     * The format used before the reference code: 6 digit zero padded payment id +
     * first 8 hex characters of the payment UUID. Kept so payments already charged
     * under it still match.
     */
    public static function legacy(Payment $payment): string
    {
        return str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)
            .substr(str_replace('-', '', (string) $payment->uuid), 0, 8);
    }

    /** True when the gateway's invoice number is this payment's current or legacy one. */
    public static function matches(Payment $payment, mixed $invoice): bool
    {
        return is_string($invoice) && ($invoice === self::for($payment) || $invoice === self::legacy($payment));
    }

    /** The reference code number inside an invoice number such as "AUTH-001020", or null. */
    public static function referenceFromInvoice(string $invoice): ?int
    {
        return preg_match('/^(?:[A-Z0-9]{1,10}-)?(\d{6,})$/', $invoice, $m) ? (int) $m[1] : null;
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
