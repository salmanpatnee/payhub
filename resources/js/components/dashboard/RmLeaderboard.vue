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
    completedCount: number;
    conversionRate: number;
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
            completedCount: r.completedCount,
            conversionRate: r.conversionRate,
        }))
        .filter((r) => r.amounts.length > 0 || r.completedCount > 0)
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
            <h3 class="text-sm font-semibold">RM Leaderboard</h3>
            <span v-if="props.currency" class="text-[11px] uppercase tracking-wider text-muted-foreground">
                {{ props.currency }}
            </span>
        </div>
        <div v-if="displayRows.length === 0" class="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
            No RM activity in this range.
        </div>
        <table v-else class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-widest text-muted-foreground">
                    <th class="pb-2 font-semibold">RM</th>
                    <th class="pb-2 text-right font-semibold">Revenue</th>
                    <th class="pb-2 text-right font-semibold">Paid</th>
                    <th class="pb-2 text-right font-semibold">Conv.</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in displayRows" :key="r.id ?? r.name" class="border-t border-border/50">
                    <td class="py-1.5 align-top">
                        <div class="font-medium">{{ r.name }}</div>
                        <div v-if="r.amounts.length" class="mt-1 space-y-1">
                            <div v-for="a in r.amounts" :key="a.code" class="flex items-center gap-1.5">
                                <span class="w-7 shrink-0 text-[9px] uppercase tracking-wide text-muted-foreground/80">
                                    {{ a.code }}
                                </span>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full"
                                        :style="{ width: barPct(a) + '%', background: barColor[a.code] }"
                                    />
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="py-1.5 text-right align-top">
                        <div v-if="r.amounts.length" class="space-y-1">
                            <div v-for="a in r.amounts" :key="a.code" class="font-mono tabular-nums">
                                {{ formatMoney(a.value, a.code) }}
                            </div>
                        </div>
                        <span v-else class="text-muted-foreground">—</span>
                    </td>
                    <td class="py-1.5 text-right tabular-nums align-top">{{ r.completedCount }}</td>
                    <td class="py-1.5 text-right tabular-nums align-top">{{ r.conversionRate }}%</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
