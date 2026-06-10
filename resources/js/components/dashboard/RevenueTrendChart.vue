<script setup lang="ts">
import { VisArea, VisAxis, VisLine, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import { formatMoney } from '@/lib/utils';
import type { RevenueTrendPoint } from '@/types/dashboard';

const props = defineProps<{ points: RevenueTrendPoint[] }>();

interface Row {
    t: number;
    usd: number;
    gbp: number;
}

const rows = computed<Row[]>(() => {
    const byDate = new Map<string, Row>();
    for (const p of props.points) {
        const key = p.date;
        const row = byDate.get(key) ?? { t: new Date(key).getTime(), usd: 0, gbp: 0 };
        if (p.currency === 'gbp') {
            row.gbp += p.total;
        } else {
            row.usd += p.total;
        }
        byDate.set(key, row);
    }
    return [...byDate.values()].sort((a, b) => a.t - b.t);
});

const hasGbp = computed(() => rows.value.some((r) => r.gbp > 0));

const x = (d: Row) => d.t;
const yUsd = (d: Row) => d.usd / 100;
const yGbp = (d: Row) => d.gbp / 100;

function formatDate(t: number): string {
    return new Date(t).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Revenue Trend</h3>
            <div class="flex items-center gap-3 text-xs text-muted-foreground">
                <span class="flex items-center gap-1"><span class="size-2 rounded-full" style="background: var(--chart-1)" /> USD</span>
                <span v-if="hasGbp" class="flex items-center gap-1"><span class="size-2 rounded-full" style="background: var(--chart-2)" /> GBP</span>
            </div>
        </div>
        <div v-if="rows.length === 0" class="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
            No completed payments in this range.
        </div>
        <VisXYContainer v-else :data="rows" :height="240">
            <VisArea :x="x" :y="yUsd" color="var(--chart-1)" :opacity="0.12" />
            <VisLine :x="x" :y="yUsd" color="var(--chart-1)" />
            <VisLine v-if="hasGbp" :x="x" :y="yGbp" color="var(--chart-2)" />
            <VisAxis
                type="x"
                :tick-line="false"
                :domain-line="false"
                :grid-line="false"
                :tick-format="formatDate"
                :num-ticks="6"
            />
            <VisAxis
                type="y"
                :tick-line="false"
                :domain-line="false"
                :tick-format="(d: number) => formatMoney(d * 100, 'usd')"
                :num-ticks="4"
            />
        </VisXYContainer>
    </div>
</template>
