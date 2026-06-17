<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ provider: string }>();

const config: Record<string, { color: string; label: string; path: string }> = {
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
};

const c = computed(() => config[props.provider] ?? config.stripe);
</script>

<template>
    <svg :class="['size-4 flex-shrink-0', c.color]" viewBox="0 0 24 24" fill="currentColor" :aria-label="c.label" role="img">
        <path :d="c.path" />
    </svg>
</template>
