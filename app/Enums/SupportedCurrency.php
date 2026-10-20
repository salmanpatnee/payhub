<?php

namespace App\Enums;

enum SupportedCurrency: string
{
    case USD = 'usd';
    case GBP = 'gbp';
    // Only MPGS accounts flagged supports_pkr can take PKR (CurrencySupportResolver). Agents need a PKR slot.
    case PKR = 'pkr';

    public function label(): string
    {
        return match ($this) {
            self::USD => 'USD',
            self::GBP => 'GBP',
            self::PKR => 'PKR',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $currency) => $currency->value, self::cases());
    }
}
