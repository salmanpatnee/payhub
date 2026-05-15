<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string }>();

const config: Record<string, { dot: string; wrap: string; pulse: boolean }> = {
    completed: {
        dot: 'bg-emerald-500',
        wrap: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-400 dark:ring-emerald-800',
        pulse: false,
    },
    pending: {
        dot: 'bg-amber-400',
        wrap: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/50 dark:text-amber-400 dark:ring-amber-800',
        pulse: true,
    },
    failed: {
        dot: 'bg-red-500',
        wrap: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/50 dark:text-red-400 dark:ring-red-800',
        pulse: false,
    },
    cancelled: {
        dot: 'bg-zinc-400',
        wrap: 'bg-zinc-100 text-zinc-500 ring-zinc-200 dark:bg-zinc-800/50 dark:text-zinc-500 dark:ring-zinc-700',
        pulse: false,
    },
};

const c = computed(() => config[props.status] ?? config.cancelled);
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5',
            'text-[10px] font-bold uppercase tracking-widest ring-1',
            c.wrap,
        ]"
    >
        <span
            :class="['size-1.5 rounded-full flex-shrink-0', c.dot, c.pulse ? 'animate-pulse' : '']"
        />
        {{ status }}
    </span>
</template>
