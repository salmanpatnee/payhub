<?php

namespace App\Services\Kurv;

use Carbon\CarbonImmutable;

/**
 * What Kurv says about one payment request (GET /payment-requests/{id}) or one history
 * row (GET /payments). One of the only classes that know Kurv's field names (spec 0010).
 *
 * Step 0 sandbox check: a request is `pending` until paid, then `success` with a
 * `payment_id`. A decline leaves the request `pending` and shows only as a history row with
 * status `NOK` (`status_label` `failed`, reason in `result_description`). Approved history
 * rows are `ACK`.
 */
final class KurvLookupReply
{
    public const STATE_SUCCESS = 'success';

    public const STATE_PENDING = 'pending';

    public const STATE_FAILED = 'failed';

    public const STATE_CLOSED = 'closed';

    public const STATE_UNKNOWN = 'unknown';

    /**
     * UNVERIFIED: no expired or voided request has been seen yet (spec 0010 Follow-up).
     * Only these exact values mean "the link is closed"; anything else is `unknown`.
     */
    private const CLOSED_STATUSES = ['expired', 'voided', 'void', 'cancelled', 'canceled'];

    public function __construct(
        public readonly string $state,
        public readonly ?string $status,
        public readonly ?string $transactionId,
        public readonly ?string $paymentId,
        public readonly ?int $amountCents,
        public readonly ?string $currency,
        public readonly ?string $paymentType,
        public readonly ?CarbonImmutable $paidAt,
        public readonly ?string $reason,
        public readonly ?string $resultCode,
    ) {}

    /**
     * @param  array<string, mixed>|null  $body  the decoded reply of GET /payment-requests/{id}
     */
    public static function fromRequestBody(?array $body): ?self
    {
        $t = is_array($body['transaction'] ?? null) ? $body['transaction'] : null;

        if ($t === null) {
            return null;
        }

        $status = self::string($t['status'] ?? null);

        return new self(
            state: match (true) {
                $status === 'success' => self::STATE_SUCCESS,
                $status === 'pending' => self::STATE_PENDING,
                $status !== null && in_array(strtolower($status), self::CLOSED_STATUSES, true) => self::STATE_CLOSED,
                default => self::STATE_UNKNOWN,
            },
            status: $status,
            transactionId: self::string($t['transaction_id'] ?? null),
            paymentId: self::string($t['payment_id'] ?? null),
            amountCents: KurvAmount::decimalToCents($t['amount'] ?? null),
            currency: self::lower($t['currency'] ?? null),
            paymentType: self::string($t['payment_type'] ?? null),
            paidAt: self::date($t['payment_date'] ?? null),
            reason: self::string($t['status_description'] ?? null),
            resultCode: null,
        );
    }

    /**
     * @param  array<string, mixed>  $row  one entry of the `payments` list, or the `payment` of GET /payments/{id}
     */
    public static function fromPaymentRow(array $row): self
    {
        $status = self::string($row['status'] ?? null);

        return new self(
            state: match ($status) {
                'ACK' => self::STATE_SUCCESS,
                'NOK' => self::STATE_FAILED,
                default => self::STATE_UNKNOWN,
            },
            status: $status,
            transactionId: self::string($row['transaction_id'] ?? null),
            paymentId: self::string($row['payment_id'] ?? null),
            amountCents: KurvAmount::decimalToCents($row['amount'] ?? null),
            currency: self::lower($row['currency'] ?? null),
            paymentType: self::string($row['payment_type'] ?? null),
            paidAt: self::date($row['payment_date'] ?? null),
            reason: self::string($row['result_description'] ?? null),
            resultCode: self::string($row['result_code'] ?? null),
        );
    }

    /** A sale (DB, also written CC.DB in Kurv's docs), never a pre-authorization or refund. */
    public function isSale(): bool
    {
        $type = strtoupper((string) $this->paymentType);

        return $type === 'DB' || str_ends_with($type, '.DB');
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function lower(mixed $value): ?string
    {
        $string = self::string($value);

        return $string === null ? null : strtolower($string);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            // Kurv sends UTC. Eloquent writes a date as the wall clock of its own timezone, so convert to the app timezone first.
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
