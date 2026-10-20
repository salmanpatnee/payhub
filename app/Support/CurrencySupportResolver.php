<?php

namespace App\Support;

use App\Enums\SupportedCurrency;

/**
 * Single source of truth for "does this provider/account support this currency".
 *
 * Viva is GBP-only and Clover is USD-only, both as flat platform rules (every
 * account is provisioned with that single currency — see CloverAccount). Square
 * is locked to whatever currency the individual account was provisioned with
 * (nullable — a Square account with no currency set accepts either). Stripe
 * and Revolut are multi-currency for every account, and so is MPGS for USD and
 * GBP (a currency the bank has not enabled fails at pay time, spec 0005 AC-2).
 *
 * PKR is the exception: only an MPGS account flagged supports_pkr accepts it, and
 * no other provider ever does.
 */
class CurrencySupportResolver
{
    public static function supports(string $provider, ?string $accountCurrency, string $currency, bool $accountAcceptsPkr = false): bool
    {
        if ($currency === SupportedCurrency::PKR->value) {
            return $provider === 'mpgs' && $accountAcceptsPkr;
        }

        return match ($provider) {
            'viva' => $currency === SupportedCurrency::GBP->value,
            'clover' => $currency === SupportedCurrency::USD->value,
            'square' => $accountCurrency === null || $accountCurrency === $currency,
            default => true,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function currenciesFor(string $provider, ?string $accountCurrency, bool $accountAcceptsPkr = false): array
    {
        return array_values(array_filter(
            SupportedCurrency::values(),
            fn (string $currency) => self::supports($provider, $accountCurrency, $currency, $accountAcceptsPkr)
        ));
    }
}
