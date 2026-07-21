<?php

use App\Support\CurrencySupportResolver;

it('resolves currency support per provider and account currency', function (string $provider, ?string $accountCurrency, string $currency, bool $expected) {
    expect(CurrencySupportResolver::supports($provider, $accountCurrency, $currency))->toBe($expected);
})->with([
    // Stripe: multi-currency for every account, accountCurrency is irrelevant.
    ['stripe', null, 'usd', true],
    ['stripe', null, 'gbp', true],

    // Revolut: multi-currency for every account.
    ['revolut', null, 'usd', true],
    ['revolut', null, 'gbp', true],

    // Square: locked to whatever currency the account was provisioned with;
    // a null account currency accepts either.
    ['square', null, 'usd', true],
    ['square', null, 'gbp', true],
    ['square', 'usd', 'usd', true],
    ['square', 'usd', 'gbp', false],
    ['square', 'gbp', 'gbp', true],
    ['square', 'gbp', 'usd', false],

    // Viva: GBP-only as a flat platform rule, regardless of accountCurrency.
    ['viva', null, 'gbp', true],
    ['viva', null, 'usd', false],
    ['viva', 'usd', 'gbp', true],
]);

it('lists currencies for a provider/account combination', function () {
    expect(CurrencySupportResolver::currenciesFor('stripe', null))->toBe(['usd', 'gbp']);
    expect(CurrencySupportResolver::currenciesFor('revolut', null))->toBe(['usd', 'gbp']);
    expect(CurrencySupportResolver::currenciesFor('viva', null))->toBe(['gbp']);
    expect(CurrencySupportResolver::currenciesFor('square', null))->toBe(['usd', 'gbp']);
    expect(CurrencySupportResolver::currenciesFor('square', 'usd'))->toBe(['usd']);
    expect(CurrencySupportResolver::currenciesFor('square', 'gbp'))->toBe(['gbp']);
});
