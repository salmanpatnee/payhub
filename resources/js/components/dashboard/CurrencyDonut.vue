<script setup lang="ts">
import { VisDonut, VisSingleContainer } from '@unovis/vue';
import { computed } from 'vue';
import { formatMoney } from '@/lib/utils';
import type { MoneyByCurrency } from '@/types/dashboard';

const props = defineProps<{ split: MoneyByCurrency }>();

const COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)'];

const segments = computed(() =>
    Object.entries(props.split)
        .map(([currency, cents]) => ({ currency, cents }))
        .filter((s) => s.cents > 0),
);

const total = computed(() => segments.value.reduce((sum, s) => sum + s.cents, 0));

const value = (d: { cents: number }) => d.cents;
const color = (_d: unknown, i: number) => COLORS[i % COLORS.length];
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <h3 class="mb-3 text-sm font-semibold">Currency Split</h3>
        <div v-if="segments.length === 0" class="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
            No completed revenue.
        </div>
        <template v-else>
            <VisSingleContainer :data="segments" :height="180">
                <VisDonut :value="value" :color="color" :arc-width="28" :pad-angle="0.02" />
            </VisSingleContainer>
            <div class="mt-3 space-y-1.5">
                <div
                    v-for="(s, i) in segments"
                    :key="s.currency"
                    class="flex items-center justify-between text-xs"
                >
                    <span class="flex items-center gap-2">
                        <span class="size-2.5 rounded-full" :style="{ background: COLORS[i % COLORS.length] }" />
                        <span class="uppercase">{{ s.currency }}</span>
                    </span>
                    <span class="font-mono text-muted-foreground">
                        {{ formatMoney(s.cents, s.currency) }}
                        <span class="ml-1">({{ Math.round((s.cents / total) * 100) }}%)</span>
                    </span>
                </div>
            </div>
        </template>
    </div>
</template>
