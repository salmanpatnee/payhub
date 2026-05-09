<script setup lang="ts">
import { LockIcon } from 'lucide-vue-next'

const props = defineProps<{
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
}>()
</script>

<template>
    <div
        :data-brand="brand.slug"
        :style="`--brand-primary: ${brand.primary_color}; --brand-secondary: ${brand.secondary_color}`"
        class="min-h-svh flex flex-col items-center justify-center bg-muted/40 px-4 py-10"
    >
        <!-- Logo area — brand logo or brand name fallback -->
        <div class="mb-8 flex flex-col items-center gap-3">
            <img
                v-if="brand.logo_url"
                :src="brand.logo_url"
                :alt="brand.name"
                class="h-10 max-w-[180px] w-auto object-contain"
            />
            <span v-else class="font-semibold text-lg">{{ brand.name }}</span>
        </div>

        <!-- Card slot — max-w-md, full-width on mobile -->
        <div class="w-full max-w-md">
            <slot />
        </div>

        <!-- Footer — Secured by Stripe -->
        <p class="mt-8 text-xs text-muted-foreground flex items-center gap-2">
            <LockIcon class="size-3" />
            Secured by Stripe
        </p>
    </div>
</template>
