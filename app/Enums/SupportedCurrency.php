<?php

namespace App\Enums;

enum SupportedCurrency: string
{
    case USD = 'usd';
    case GBP = 'gbp';

    public function label(): string
    {
        return match ($this) {
            self::USD => 'USD',
            self::GBP => 'GBP',
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
