<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/utils';
import type { PerformanceRow } from '@/types/dashboard';

const props = defineProps<{
    rows: PerformanceRow[];
    /** Narrows to a single currency when the dashboard's currency filter is active. */
    currency?: string;
}>();

/** Stable left-to-right currency order — never lets one currency implicitly win. */
const CURRENCY_ORDER = ['usd', 'gbp'];

interface DisplayAmount {
    code: string;
    value: number;
}

interface DisplayRow {
    id: number | null;
    name: string;
    amounts: DisplayAmount[];
}

const displayRows = computed<DisplayRow[]>(() => {
    const currencies = props.currency ? [props.currency] : CURRENCY_ORDER;

    return props.rows
        .map((r) => ({
            id: r.id,
            name: r.name,
            amounts: currencies
                .filter((c) => (r.revenue[c] ?? 0) > 0)
                .map((c) => ({ code: c, value: r.revenue[c] })),
        }))
        .filter((r) => r.amounts.length > 0)
        .slice(0, 8);
});

/** Each currency's bar is scaled against the largest amount in that same currency — never cross-currency. */
const maxByCurrency = computed(() => {
    const max: Record<string, number> = {};
    for (const r of displayRows.value) {
        for (const a of r.amounts) {
            max[a.code] = Math.max(max[a.code] ?? 0, a.value);
        }
    }
    return max;
});

const barColor: Record<string, string> = { usd: 'var(--chart-1)', gbp: 'var(--chart-3)' };

function barPct(a: DisplayAmount): number {
    const max = maxByCurrency.value[a.code] || 1;
    return Math.round((a.value / max) * 100);
}
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Brand Performance</h3>
            <span v-if="props.currency" class="text-[11px] uppercase tracking-wider text-muted-foreground">
                {{ props.currency }}
            </span>
        </div>
        <div v-if="displayRows.length === 0" class="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
            No completed revenue in this range.
        </div>
        <div v-else class="space-y-3">
            <div v-for="b in displayRows" :key="b.id ?? b.name">
                <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                    <span class="truncate font-medium" :title="b.name">{{ b.name }}</span>
                    <span class="flex shrink-0 gap-3 font-mono tabular-nums text-muted-foreground">
                        <span v-for="a in b.amounts" :key="a.code">{{ formatMoney(a.value, a.code) }}</span>
                    </span>
                </div>
                <div class="space-y-1">
                    <div v-for="a in b.amounts" :key="a.code" class="flex items-center gap-2">
                        <span class="w-8 shrink-0 text-[10px] uppercase tracking-wide text-muted-foreground/80">
                            {{ a.code }}
                        </span>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full"
                                :style="{ width: barPct(a) + '%', background: barColor[a.code] }"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
