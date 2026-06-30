<script setup lang="ts">
import { computed } from 'vue';
import type { DashboardFunnel } from '@/types/dashboard';

const props = defineProps<{ funnel: DashboardFunnel }>();

const stages = computed(() => {
    const total = props.funnel.total || 1;
    return [
        { label: 'Created', value: props.funnel.total, color: 'var(--chart-3)' },
        { label: 'Pending', value: props.funnel.pending, color: 'var(--chart-4)' },
        { label: 'Completed', value: props.funnel.completed, color: 'var(--chart-2)' },
    ].map((s) => ({ ...s, pct: Math.round((s.value / total) * 100) }));
});

const leakage = computed(() => [
    { label: 'Failed', value: props.funnel.failed },
    { label: 'Expired', value: props.funnel.expired },
    { label: 'Cancelled', value: props.funnel.cancelled },
]);
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <h3 class="mb-3 text-sm font-semibold">Conversion Funnel</h3>
        <div class="space-y-3">
            <div v-for="stage in stages" :key="stage.label">
                <div class="mb-1 flex items-center justify-between text-xs">
                    <span class="font-medium">{{ stage.label }}</span>
                    <span class="text-muted-foreground">{{ stage.value }} · {{ stage.pct }}%</span>
                </div>
                <div class="h-6 w-full overflow-hidden rounded-md bg-muted">
                    <div
                        class="h-full rounded-md transition-all"
                        :style="{ width: stage.pct + '%', background: stage.color }"
                    />
                </div>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 border-t border-border/60 pt-3 text-xs text-muted-foreground">
            <span v-for="l in leakage" :key="l.label">
                {{ l.label }}: <span class="font-medium text-foreground">{{ l.value }}</span>
            </span>
        </div>
    </div>
</template>
