<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    label: string;
    icon?: Component;
    /** Primary big value (already formatted). */
    value: string;
    /** Optional secondary lines (e.g. GBP, counts). */
    sublines?: string[];
    /** Optional accent color for the value, e.g. 'text-amber-600'. */
    accent?: string;
    /** Larger value text for the headline cards. */
    hero?: boolean;
}>();
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card p-4 shadow-sm">
        <div class="flex items-center justify-between">
            <span class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                {{ label }}
            </span>
            <component :is="icon" v-if="icon" class="size-4 text-muted-foreground/70" />
        </div>
        <div
            class="mt-2 font-semibold tracking-tight"
            :class="[accent, hero ? 'text-3xl' : 'text-2xl']"
        >
            {{ value }}
        </div>
        <div v-if="sublines?.length" class="mt-1 space-y-0.5">
            <p
                v-for="(line, i) in sublines"
                :key="i"
                class="text-xs text-muted-foreground"
            >
                {{ line }}
            </p>
        </div>
    </div>
</template>
