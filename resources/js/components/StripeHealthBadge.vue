<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string | null }>();

const config: Record<string, { label: string; dot: string; wrap: string }> = {
    healthy: {
        label: 'Healthy',
        dot: 'bg-emerald-500',
        wrap: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-400 dark:ring-emerald-800',
    },
    needs_attention: {
        label: 'Needs attention',
        dot: 'bg-amber-400',
        wrap: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/50 dark:text-amber-400 dark:ring-amber-800',
    },
    restricted: {
        label: 'Restricted',
        dot: 'bg-red-500',
        wrap: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/50 dark:text-red-400 dark:ring-red-800',
    },
    unreachable: {
        label: 'Unreachable',
        dot: 'bg-zinc-400',
        wrap: 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-zinc-800/50 dark:text-zinc-400 dark:ring-zinc-700',
    },
    none: {
        label: 'Not checked yet',
        dot: 'bg-zinc-300',
        wrap: 'bg-zinc-50 text-zinc-500 ring-zinc-200 dark:bg-zinc-900/50 dark:text-zinc-500 dark:ring-zinc-700',
    },
};

const c = computed(() => config[props.status ?? 'none'] ?? config.none);
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5',
            'text-[10px] font-bold uppercase tracking-widest ring-1',
            c.wrap,
        ]"
    >
        <span :class="['size-1.5 rounded-full flex-shrink-0', c.dot]" />
        {{ c.label }}
    </span>
</template>
