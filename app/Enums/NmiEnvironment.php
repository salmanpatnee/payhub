<?php

namespace App\Enums;

enum NmiEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
