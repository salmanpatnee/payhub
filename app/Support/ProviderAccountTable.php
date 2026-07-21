<?php

namespace App\Support;

class ProviderAccountTable
{
    public static function for(?string $provider): string
    {
        return match ($provider) {
            'revolut' => 'revolut_accounts',
            'square' => 'square_accounts',
            'viva' => 'viva_accounts',
            default => 'stripe_accounts',
        };
    }
}
