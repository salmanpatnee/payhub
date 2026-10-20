<?php

namespace App\Enums;

enum SupportedCurrency: string
{
    case USD = 'usd';
    case GBP = 'gbp';
    // Only MPGS accounts flagged supports_pkr can take PKR (CurrencySupportResolver).
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

    /**
     * The currencies every provider and every agent/user account slot may use
     * (everything except PKR, which is MPGS-only and admin-only for now).
     *
     * @return array<int, self>
     */
    public static function standard(): array
    {
        return array_values(array_filter(self::cases(), fn (self $currency) => $currency !== self::PKR));
    }

    /**
     * @return array<int, string>
     */
    public static function standardValues(): array
    {
        return array_map(fn (self $currency) => $currency->value, self::standard());
    }
}
