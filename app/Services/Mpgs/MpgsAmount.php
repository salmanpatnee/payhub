<?php

namespace App\Services\Mpgs;

/**
 * Converts between PayHub's integer minor units and the gateway's decimal
 * string using integer math only (spec 0005, Value sourcing). USD, GBP and PKR
 * all have 2 decimals. The decimal string exists only in gateway requests and is
 * never stored.
 */
class MpgsAmount
{
    public static function toGateway(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }

    /**
     * Parse a gateway amount ("10.5", 10.5, "10.50", 10) into minor units, or
     * null when it is not a plain amount with at most 2 decimals. Parsed from
     * the string form so no float rounding can turn a mismatch into a match.
     */
    public static function toMinorUnits(mixed $gatewayAmount): ?int
    {
        if (! is_int($gatewayAmount) && ! is_float($gatewayAmount) && ! is_string($gatewayAmount)) {
            return null;
        }

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim((string) $gatewayAmount), $m)) {
            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
