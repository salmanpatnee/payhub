<script setup lang="ts">
import { Lightbulb } from 'lucide-vue-next';

defineProps<{ insights: string[] }>();
</script>

<template>
    <div
        v-if="insights.length"
        class="rounded-xl border border-border/70 bg-gradient-to-br from-card to-muted/30 p-4 shadow-sm"
    >
        <div class="mb-2 flex items-center gap-2">
            <Lightbulb class="size-4 text-amber-500" />
            <h3 class="text-sm font-semibold">Insights</h3>
        </div>
        <ul class="grid gap-1.5 md:grid-cols-2">
            <li
                v-for="(insight, i) in insights"
                :key="i"
                class="flex items-start gap-2 text-sm text-muted-foreground"
            >
                <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary/70" />
                <span>{{ insight }}</span>
            </li>
        </ul>
    </div>
</template>
