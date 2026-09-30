<script setup lang="ts">
import { computed } from 'vue';
import { statusStyle } from '@/lib/stripeHealth';

const props = defineProps<{ status: string | null }>();

const c = computed(() => statusStyle(props.status));
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1 rounded-md px-2 py-0.5',
            'text-xs font-medium ring-1 ring-inset',
            c.wrap,
        ]"
    >
        <component :is="c.icon" class="size-3 flex-shrink-0" />
        {{ c.label }}
    </span>
</template>
