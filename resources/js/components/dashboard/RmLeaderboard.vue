<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/utils';
import type { PerformanceRow } from '@/types/dashboard';

const props = defineProps<{
    rows: PerformanceRow[];
    currency?: string;
}>();

const cur = computed(() => props.currency ?? 'usd');

const ranked = computed(() =>
    props.rows
        .map((r) => ({ ...r, value: r.revenue[cur.value] ?? 0 }))
        .filter((r) => r.value > 0 || r.completedCount > 0)
        .sort((a, b) => b.value - a.value)
        .slice(0, 8),
);

const max = computed(() => ranked.value[0]?.value ?? 1);
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold">RM Leaderboard</h3>
            <span class="text-[11px] uppercase tracking-wider text-muted-foreground">{{ cur }}</span>
        </div>
        <div v-if="ranked.length === 0" class="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
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
                <tr v-for="r in ranked" :key="r.id ?? r.name" class="border-t border-border/50">
                    <td class="py-1.5">
                        <div class="font-medium">{{ r.name }}</div>
                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full"
                                :style="{ width: Math.round((r.value / max) * 100) + '%', background: 'var(--chart-2)' }"
                            />
                        </div>
                    </td>
                    <td class="py-1.5 text-right font-mono">{{ formatMoney(r.value, cur) }}</td>
                    <td class="py-1.5 text-right tabular-nums">{{ r.completedCount }}</td>
                    <td class="py-1.5 text-right tabular-nums">{{ r.conversionRate }}%</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
