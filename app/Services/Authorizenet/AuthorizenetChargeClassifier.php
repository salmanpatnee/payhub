<?php

namespace App\Services\Authorizenet;

use App\Models\Payment;

/**
 * Pure classification of one getTransactionDetails result (spec 0006, AC-6).
 * No I/O: the caller reads the transaction, this decides which bucket it is in.
 */
class AuthorizenetChargeClassifier
{
    public const APPROVED = 'approved';

    public const HELD = 'held';

    public const DECLINED = 'declined';

    public const UNKNOWN = 'unknown';

    private const APPROVED_STATUSES = ['capturedPendingSettlement', 'settledSuccessfully'];

    private const HELD_STATUSES = ['FDSPendingReview', 'FDSAuthorizedPendingReview', 'underReview', 'authorizedPendingRelease'];

    private const DECLINED_STATUSES = ['declined', 'failedReview'];

    /**
     * @param  array<string, mixed>  $transaction  the `transaction` object from getTransactionDetails
     * @return self::APPROVED|self::HELD|self::DECLINED|self::UNKNOWN
     */
    public static function classify(array $transaction, Payment $payment): string
    {
        $code = (int) ($transaction['responseCode'] ?? 0);
        $status = (string) ($transaction['transactionStatus'] ?? '');

        // The invoice number is the link to this payment; a mismatch is never trusted
        // in any bucket, not even held or declined.
        if (! AuthorizenetInvoice::matches($payment, $transaction['order']['invoiceNumber'] ?? null)) {
            return self::UNKNOWN;
        }

        if ($code === 1 && in_array($status, self::APPROVED_STATUSES, true)) {
            return AuthorizenetInvoice::toCents($transaction['authAmount'] ?? null) === $payment->amount
                ? self::APPROVED
                : self::UNKNOWN;
        }

        if (in_array($status, self::HELD_STATUSES, true) || $code === 4) {
            return self::HELD;
        }

        if ($code === 2 || in_array($status, self::DECLINED_STATUSES, true)) {
            return self::DECLINED;
        }

        return self::UNKNOWN;
    }
}
