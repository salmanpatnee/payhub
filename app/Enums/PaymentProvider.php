<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case Revolut = 'revolut';
    case Square = 'square';
    case Viva = 'viva';
    case Clover = 'clover';
    case Mpgs = 'mpgs';
    case Authorizenet = 'authorizenet';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Revolut => 'Revolut',
            self::Square => 'Square',
            self::Viva => 'Viva',
            self::Clover => 'Clover',
            self::Mpgs => 'MPGS',
            self::Authorizenet => 'Authorize.net',
        };
    }
}
