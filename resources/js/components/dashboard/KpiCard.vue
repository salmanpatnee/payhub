<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    label: string;
    icon?: Component;
    /** Single-value mode: primary big value (already formatted). Ignored when `dual` is set. */
    value?: string;
    /** Dual-currency mode: renders every entry at equal size — no currency implicitly wins. */
    dual?: boolean;
    amounts?: { code: string; formatted: string }[];
    /** Optional secondary lines (e.g. currency mix, avg value, counts) — always demoted below the primary figure(s). */
    sublines?: string[];
    /** Optional accent color for the value(s), e.g. 'text-amber-600'. */
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
            v-if="dual"
            class="mt-2 grid gap-4"
            :class="(amounts?.length ?? 0) > 1 ? 'grid-cols-2' : 'grid-cols-1'"
        >
            <div v-for="a in amounts" :key="a.code" class="min-w-0">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/80">
                    {{ a.code }}
                </div>
                <div
                    class="mt-0.5 truncate font-semibold tracking-tight tabular-nums"
                    :class="[accent, hero ? 'text-2xl' : 'text-xl']"
                >
                    {{ a.formatted }}
                </div>
            </div>
        </div>
        <div
            v-else
            class="mt-2 font-semibold tracking-tight"
            :class="[accent, hero ? 'text-3xl' : 'text-2xl']"
        >
            {{ value }}
        </div>

        <div
            v-if="sublines?.length"
            class="space-y-0.5"
            :class="dual ? 'mt-3 border-t border-border/50 pt-2' : 'mt-1'"
        >
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
