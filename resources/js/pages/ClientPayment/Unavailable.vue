<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { CheckCircle2, XCircle, Ban } from 'lucide-vue-next'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { computed } from 'vue'

const props = defineProps<{
    status: 'completed' | 'failed' | 'cancelled'
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
}>()

const content = computed(() => {
    const map: Record<string, { title: string; description: string; pageTitle: string; icon: string }> = {
        completed: {
            title:       'Already paid',
            description: 'This payment has already been completed. No further action is needed.',
            pageTitle:   `Already paid — ${props.brand.name}`,
            icon:        'check',
        },
        failed: {
            title:       'Payment link unavailable',
            description: 'Payment was unsuccessful. Please contact us to arrange a new payment.',
            pageTitle:   `Payment unavailable — ${props.brand.name}`,
            icon:        'x',
        },
        cancelled: {
            title:       'Link no longer active',
            description: 'This payment link has been cancelled. Please contact us if you need assistance.',
            pageTitle:   `Link no longer active — ${props.brand.name}`,
            icon:        'ban',
        },
    }
    return map[props.status] ?? map['cancelled']
})
</script>

<template>
    <PaymentLayout :brand="props.brand">
        <Head :title="content.pageTitle" />

        <div class="text-center space-y-4">
            <!-- Status-aware icon -->
            <div
                v-if="status === 'completed'"
                class="flex h-16 w-16 items-center justify-center rounded-full bg-green-100 mx-auto"
            >
                <CheckCircle2 class="size-8 text-green-600" />
            </div>
            <div
                v-else-if="status === 'failed'"
                class="flex h-16 w-16 items-center justify-center rounded-full bg-red-100 mx-auto"
            >
                <XCircle class="size-8 text-red-600" />
            </div>
            <div
                v-else
                class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 mx-auto"
            >
                <Ban class="size-8 text-slate-500" />
            </div>

            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ content.title }}</h1>
                <p class="text-sm text-slate-500 mt-1">{{ content.description }}</p>
            </div>
        </div>
    </PaymentLayout>
</template>
