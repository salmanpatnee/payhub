<?php

namespace App\Enums;

enum AuthorizenetEnvironment: string
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
