<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case Revolut = 'revolut';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Revolut => 'Revolut',
        };
    }
}
