<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ provider: string }>();

const config: Record<string, { color: string; label: string; path: string; viewBox?: string }> = {
    stripe: {
        color: 'text-indigo-600 dark:text-indigo-400',
        label: 'Stripe',
        path: 'M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.591-7.305z',
    },
    revolut: {
        color: 'text-zinc-800 dark:text-zinc-200',
        label: 'Revolut',
        path: 'M20.9133 6.9566C20.9133 3.1208 17.7898 0 13.9503 0H2.424v3.8605h10.9782c1.7376 0 3.177 1.3651 3.2087 3.043.016.84-.2994 1.633-.8878 2.2324-.5886.5998-1.375.9303-2.2144.9303H9.2322a.2756.2756 0 0 0-.2755.2752v3.431c0 .0585.018.1142.052.1612L16.2646 24h5.3114l-7.2727-10.094c3.6625-.1838 6.61-3.2612 6.61-6.9494zM6.8943 5.9229H2.424V24h4.4704z',
    },
    square: {
        color: 'text-blue-600 dark:text-blue-400',
        label: 'Square',
        path: 'M4.01 3.5A2.51 2.51 0 0 0 1.5 6.01v11.98A2.51 2.51 0 0 0 4.01 20.5h15.98a2.51 2.51 0 0 0 2.51-2.51V6.01a2.51 2.51 0 0 0-2.51-2.51H4.01zM5 6.5h14v11H5v-11zm4 3a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1H9z',
    },
    viva: {
        color: 'text-orange-600 dark:text-orange-400',
        label: 'Viva',
        // Simple landmark/bank glyph — Viva has no single recognizable brand
        // mark like Stripe/Square, and this is redirect-only (no in-page SDK).
        path: 'M12 2 1 9h2v11h4v-7h10v7h4V9h2L12 2zm-4 9h8v9H8v-9z',
    },
    clover: {
        color: 'text-emerald-600 dark:text-emerald-400',
        label: 'Clover',
        // Clover's four-petal mark, drawn in a native 40x40 space (see viewBox below).
        viewBox: '0 0 40 40',
        path: 'M18.0259 10.433C18.0259 5.45897 13.9888 1.42017 9.01114 1.42017C4.03464 1.42017 0.000244141 5.45382 0.000244141 10.4279C0.000244141 15.4019 4.03457 19.4459 9.0134 19.4459H18.0259L18.0259 10.433Z M20.5541 10.4331C20.5541 5.45903 24.5912 1.42023 29.5689 1.42023C34.5454 1.42023 38.5798 5.45388 38.5798 10.4279C38.5798 15.402 34.5454 19.4459 29.5666 19.4459L20.5541 19.4459L20.5541 10.4331Z M20.5541 30.9872C20.5541 35.9661 24.5901 40 29.5697 40C34.5439 40 38.5798 35.9691 38.5798 30.9901C38.5798 26.0159 34.5439 21.9743 29.5666 21.9743L20.5541 21.9743V30.9872Z M18.0257 30.9872C18.0257 35.9661 13.9897 40 9.01013 40C4.03601 40 0 35.9691 0 30.9901C0 26.0159 4.03593 21.9743 9.01319 21.9743H18.0257V30.9872ZM9.01013 37.4585C12.5872 37.4585 15.4974 34.5569 15.4974 30.9901V24.504H9.01836C5.4397 24.504 2.52802 27.4249 2.52802 30.9901C2.52802 34.5569 5.43577 37.4585 9.01013 37.4585Z',
    },
};

// Explicit per-provider entries are required above — falling back to
// config.stripe for an unmapped provider silently mislabels it (this is the
// exact bug class two prior Square CSV export bugs came from; see CLAUDE.md).
const c = computed(() => config[props.provider] ?? config.stripe);
</script>

<template>
    <svg :class="['size-4 flex-shrink-0', c.color]" :viewBox="c.viewBox ?? '0 0 24 24'" fill="currentColor" :aria-label="c.label" role="img">
        <path :d="c.path" />
    </svg>
</template>
