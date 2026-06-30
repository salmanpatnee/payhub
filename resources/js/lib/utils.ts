import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

/**
 * Format an integer-cents amount as a localized currency string.
 * USD and GBP only; never mix currencies in a single figure.
 */
export function formatMoney(cents: number, currency: string): string {
    return new Intl.NumberFormat(currency === 'gbp' ? 'en-GB' : 'en-US', {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(cents / 100);
}

/**
 * Format a payment reference code as `#` + 6-digit zero-padded integer.
 * Null renders as an em dash.
 */
export function formatReferenceCode(code: number | null | undefined): string {
    return code != null ? '#' + String(code).padStart(6, '0') : '—';
}
