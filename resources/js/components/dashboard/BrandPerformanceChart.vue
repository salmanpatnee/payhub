<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/utils';
import type { PerformanceRow } from '@/types/dashboard';

const props = defineProps<{
    rows: PerformanceRow[];
    /** Currency used for the single revenue axis (defaults to usd). */
    currency?: string;
}>();

const cur = computed(() => props.currency ?? 'usd');

const bars = computed(() => {
    const ranked = props.rows
        .map((r) => ({ ...r, value: r.revenue[cur.value] ?? 0 }))
        .filter((r) => r.value > 0)
        .sort((a, b) => b.value - a.value)
        .slice(0, 8);
    const max = ranked[0]?.value ?? 1;
    return ranked.map((r) => ({ ...r, pct: Math.round((r.value / max) * 100) }));
});
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Brand Performance</h3>
            <span class="text-[11px] uppercase tracking-wider text-muted-foreground">{{ cur }}</span>
        </div>
        <div v-if="bars.length === 0" class="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
            No completed revenue in this range.
        </div>
        <div v-else class="space-y-2.5">
            <div v-for="b in bars" :key="b.id ?? b.name">
                <div class="mb-1 flex items-center justify-between text-xs">
                    <span class="truncate font-medium" :title="b.name">{{ b.name }}</span>
                    <span class="ml-2 shrink-0 font-mono text-muted-foreground">
                        {{ formatMoney(b.value, cur) }}
                    </span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full" :style="{ width: b.pct + '%', background: 'var(--chart-1)' }" />
                </div>
            </div>
        </div>
    </div>
</template>
