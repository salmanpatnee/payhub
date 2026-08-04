export const BANK_ACCOUNT_CURRENCIES = ['usd', 'gbp', 'pkr'] as const;

export type BankAccountCurrency = (typeof BANK_ACCOUNT_CURRENCIES)[number];

export const BANK_ACCOUNT_CURRENCY_LABELS: Record<BankAccountCurrency, string> = {
    usd: 'USD',
    gbp: 'GBP',
    pkr: 'PKR',
};
