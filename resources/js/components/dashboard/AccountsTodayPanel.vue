<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/utils';
import type { AccountTodayRow, DashboardFilterValues } from '@/types/dashboard';

const props = defineProps<{
    accounts: AccountTodayRow[];
    filters: DashboardFilterValues;
}>();

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Range-aware label, e.g. "1 Jul 2026 – 14 Jul 2026", "From 1 Jul 2026", "Through 14 Jul 2026".
 * Unlike the rest of the dashboard (which defaults to all-time), this panel's own
 * default — no filter applied — is "Today", since it's an operational, right-now view.
 */
const rangeLabel = computed(() => {
    const { from, to } = props.filters;

    if (from && to) {
        return `${formatDate(from)} – ${formatDate(to)}`;
    }

    if (from) {
        return `From ${formatDate(from)}`;
    }

    if (to) {
        return `Through ${formatDate(to)}`;
    }

    return 'Today';
});

interface CurrencyBar {
    currency: string;
    accepted: number;
    pending: number;
    acceptedPct: number;
    pendingPct: number;
}

interface AccountGroup {
    key: string;
    name: string;
    provider: string;
    currencies: CurrencyBar[];
}

/** One row per account (like Brand Performance / RM Leaderboard), each with a bar per currency present. */
const accountGroups = computed<AccountGroup[]>(() => {
    const groups = props.accounts
        .map((account) => {
            const currencies = new Set([
                ...Object.keys(account.accepted),
                ...Object.keys(account.pending),
            ]);

            const bars = [...currencies]
                .map((currency) => ({
                    currency,
                    accepted: account.accepted[currency] ?? 0,
                    pending: account.pending[currency] ?? 0,
                }))
                .filter((c) => c.accepted + c.pending > 0);

            return {
                key: `${account.provider}-${account.id}`,
                name: account.name,
                provider: account.provider,
                bars,
            };
        })
        .filter((g) => g.bars.length > 0);

    // Each currency's bar is scaled against the largest total in that same currency — never cross-currency.
    const maxByCurrency: Record<string, number> = {};

    for (const g of groups) {
        for (const b of g.bars) {
            maxByCurrency[b.currency] = Math.max(maxByCurrency[b.currency] ?? 0, b.accepted + b.pending);
        }
    }

    return groups.map((g) => ({
        key: g.key,
        name: g.name,
        provider: g.provider,
        currencies: g.bars.map((b) => {
            const max = maxByCurrency[b.currency] || 1;

            return {
                currency: b.currency,
                accepted: b.accepted,
                pending: b.pending,
                acceptedPct: Math.round((b.accepted / max) * 100),
                pendingPct: Math.round((b.pending / max) * 100),
            };
        }),
    }));
});
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold">By Payment Provider or Account ({{ rangeLabel }})</h3>
                <p class="text-xs text-muted-foreground">Accepted · Pending</p>
            </div>
            <div class="flex items-center gap-3 text-[11px] text-muted-foreground">
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-emerald-500" />
                    Accepted
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-amber-500" />
                    Pending
                </span>
            </div>
        </div>

        <div v-if="accountGroups.length === 0" class="flex h-[120px] items-center justify-center text-sm text-muted-foreground">
            No payments accepted or pending in this range.
        </div>
        <div v-else class="divide-y divide-border/40">
            <div v-for="g in accountGroups" :key="g.key" class="space-y-1.5 py-3 first:pt-0 last:pb-0">
                <span class="flex items-center gap-1.5 truncate text-xs font-medium" :title="`${g.name} · ${g.provider}`">
                    {{ g.name }}
                    <span class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-muted-foreground">
                        {{ g.provider }}
                    </span>
                </span>
                <div class="space-y-1.5">
                    <div v-for="c in g.currencies" :key="c.currency">
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="w-9 shrink-0 text-[10px] uppercase tracking-wide text-muted-foreground/80">
                                {{ c.currency }}
                            </span>
                            <span class="ml-2 shrink-0 font-mono text-muted-foreground">
                                Accepted {{ formatMoney(c.accepted, c.currency) }} · Pending {{ formatMoney(c.pending, c.currency) }}
                            </span>
                        </div>
                        <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-muted">
                            <div class="h-full bg-emerald-500" :style="{ width: c.acceptedPct + '%' }" />
                            <div class="h-full bg-amber-500" :style="{ width: c.pendingPct + '%' }" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
