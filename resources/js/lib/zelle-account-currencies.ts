export const ZELLE_ACCOUNT_CURRENCIES = ['usd', 'gbp'] as const;

export type ZelleAccountCurrency = (typeof ZELLE_ACCOUNT_CURRENCIES)[number];

export const ZELLE_ACCOUNT_CURRENCY_LABELS: Record<ZelleAccountCurrency, string> = {
    usd: 'USD',
    gbp: 'GBP',
};
