<?php

namespace App\Services\Whop;

/**
 * Converts between PayHub integer minor units and Whop's decimal format.
 * Integer math only, no floats. Whop's decimal format exists only at the
 * edge of WhopClient and WhopWebhookPayload (spec 0009).
 */
class WhopAmount
{
    /** 1234 becomes "12.34". */
    public static function centsToDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * "12.34", "12.3", "12" and "12.3400" become cents. Anything else (negative,
     * exponent notation, real third decimal, letters) returns null so the caller fails closed.
     */
    public static function decimalToCents(string|int|float|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2})0*)?$/', trim((string) $value), $m)) {
            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
