<?php

namespace App\Services\Worldpay;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One payment row from Worldpay's Payment Queries reply (spec 0014, AC-8). One of the only
 * classes that know Worldpay field names. Step 0 confirmed a row carries `paymentId`,
 * `transactionReference`, `lastEvent`, `timestamp`, `entity` and `value.amount` / `value.currency`.
 */
final class WorldpayLookupReply
{
    public function __construct(
        public readonly ?string $paymentId,
        public readonly ?string $transactionReference,
        public readonly ?string $lastEvent,
        public readonly ?int $amountCents,
        public readonly ?string $currency,
        public readonly ?CarbonImmutable $timestamp,
    ) {}

    /**
     * @param  array<string, mixed>|null  $json  the whole Payment Queries body
     * @return list<self>
     */
    public static function rowsFromBody(?array $json): array
    {
        $payments = $json['_embedded']['payments'] ?? null;

        if (! is_array($payments)) {
            return [];
        }

        $rows = [];

        foreach ($payments as $row) {
            if (is_array($row)) {
                $rows[] = self::fromRow($row);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        $amount = $row['value']['amount'] ?? null;
        $currency = $row['value']['currency'] ?? null;
        $time = null;

        if (is_string($row['timestamp'] ?? null)) {
            try {
                // Worldpay sends UTC. Laravel saves a date as written, so it is moved to the app timezone first,
                // otherwise paid_at shows hours early (Asia/Karachi: 5 hours).
                $time = CarbonImmutable::parse($row['timestamp'])->setTimezone((string) config('app.timezone', 'UTC'));
            } catch (Throwable) {
                $time = null;
            }
        }

        return new self(
            is_string($row['paymentId'] ?? null) ? $row['paymentId'] : null,
            is_string($row['transactionReference'] ?? null) ? $row['transactionReference'] : null,
            is_string($row['lastEvent'] ?? null) ? $row['lastEvent'] : null,
            is_int($amount) ? $amount : null,
            is_string($currency) ? strtoupper($currency) : null,
            $time,
        );
    }
}
