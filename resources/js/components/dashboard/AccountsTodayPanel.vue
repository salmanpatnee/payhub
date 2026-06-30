<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/utils';
import type { AccountTodayRow } from '@/types/dashboard';

const props = defineProps<{
    accounts: AccountTodayRow[];
}>();

interface AccountBar {
    key: string;
    name: string;
    provider: string;
    currency: string;
    accepted: number;
    pending: number;
    acceptedPct: number;
    pendingPct: number;
}

const bars = computed<AccountBar[]>(() => {
    const rows = props.accounts.flatMap((account) => {
        const currencies = new Set([
            ...Object.keys(account.accepted),
            ...Object.keys(account.pending),
        ]);

        return [...currencies].map((currency) => {
            const accepted = account.accepted[currency] ?? 0;
            const pending = account.pending[currency] ?? 0;

            return {
                key: `${account.provider}-${account.id}-${currency}`,
                name: account.name,
                provider: account.provider,
                currency,
                accepted,
                pending,
                total: accepted + pending,
            };
        });
    });

    const liveRows = rows.filter((r) => r.total > 0);

    // Scale each bar against the largest total within its own currency.
    const maxByCurrency: Record<string, number> = {};
    for (const r of liveRows) {
        maxByCurrency[r.currency] = Math.max(maxByCurrency[r.currency] ?? 0, r.total);
    }

    return liveRows.map((r) => {
        const max = maxByCurrency[r.currency] || 1;
        return {
            key: r.key,
            name: r.name,
            provider: r.provider,
            currency: r.currency,
            accepted: r.accepted,
            pending: r.pending,
            acceptedPct: Math.round((r.accepted / max) * 100),
            pendingPct: Math.round((r.pending / max) * 100),
        };
    });
});
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold">Today by account</h3>
                <p class="text-xs text-muted-foreground">Accepted today · Pending today &amp; yesterday</p>
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

        <div v-if="bars.length === 0" class="flex h-[120px] items-center justify-center text-sm text-muted-foreground">
            No payments accepted or pending today.
        </div>
        <div v-else class="space-y-3">
            <div v-for="bar in bars" :key="bar.key">
                <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                    <span class="flex items-center gap-1.5 truncate font-medium" :title="`${bar.name} · ${bar.provider}`">
                        {{ bar.name }}
                        <span class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-muted-foreground">
                            {{ bar.provider }}
                        </span>
                        <span class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-muted-foreground">
                            {{ bar.currency }}
                        </span>
                    </span>
                    <span class="ml-2 shrink-0 font-mono text-muted-foreground">
                        Accepted {{ formatMoney(bar.accepted, bar.currency) }} · Pending {{ formatMoney(bar.pending, bar.currency) }}
                    </span>
                </div>
                <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-muted">
                    <div class="h-full bg-emerald-500" :style="{ width: bar.acceptedPct + '%' }" />
                    <div class="h-full bg-amber-500" :style="{ width: bar.pendingPct + '%' }" />
                </div>
            </div>
        </div>
    </div>
</template>
