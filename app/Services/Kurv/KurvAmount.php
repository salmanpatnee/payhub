<?php

namespace App\Services\Kurv;

/**
 * Converts between PayHub integer minor units and Kurv's decimal number. Integer math
 * only, no floats in PayHub. Kurv's decimal exists only at the edge of KurvClient and
 * the reply classes (spec 0010).
 */
class KurvAmount
{
    /** 1234 becomes "12.34". */
    public static function centsToDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * 12.34, "12.3", 12 and "12.3400" become cents. Anything else (negative, exponent
     * notation, a real third decimal, letters) returns null so the caller fails closed.
     */
    public static function decimalToCents(string|int|float|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            // Fixed 4 decimals so 12.34 never reads as 12.339999.
            $value = number_format($value, 4, '.', '');
        }

        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2})0*)?$/', trim((string) $value), $m)) {
            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
