<?php

namespace App\Services\Nmi;

/**
 * Converts between PayHub integer minor units and NMI's decimal string. Integer math only,
 * no floats in PayHub. NMI's decimal exists only at the edge of NmiClient and the reply
 * classes (spec 0012).
 */
class NmiAmount
{
    /** 1234 becomes "12.34". */
    public static function centsToDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * "12.34", "12.3" and "12.3400" become cents. Anything else (negative, exponent notation,
     * a real third decimal, letters) returns null so the caller fails closed.
     */
    public static function decimalToCents(?string $value): ?int
    {
        if ($value === null || ! preg_match('/^(\d{1,12})(?:\.(\d{1,2})0*)?$/', trim($value), $m)) {
            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
