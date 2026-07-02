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
    provider?: 'stripe' | 'revolut' | 'square'
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
    <PaymentLayout :brand="props.brand" :provider="props.provider">
        <Head :title="content.pageTitle" />

        <div class="page-content text-center space-y-5">
            <!-- Status-aware icon -->
            <div v-if="status === 'completed'" class="icon-wrap icon-green mx-auto flex h-18 w-18 items-center justify-center rounded-full bg-green-50">
                <CheckCircle2 class="size-9 text-green-500" />
            </div>
            <div v-else-if="status === 'failed'" class="icon-wrap icon-red mx-auto flex h-18 w-18 items-center justify-center rounded-full bg-red-50">
                <XCircle class="size-9 text-red-500" />
            </div>
            <div v-else class="icon-wrap icon-neutral mx-auto flex h-18 w-18 items-center justify-center rounded-full bg-slate-100">
                <Ban class="size-9 text-slate-400" />
            </div>

            <div class="max-w-xs mx-auto">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ content.title }}</h1>
                <p class="text-sm text-slate-500 mt-2 leading-relaxed">{{ content.description }}</p>
            </div>

            <p class="text-xs text-slate-400 pt-2">
                Need help? Contact <span class="font-medium text-slate-600">{{ brand.name }}</span> directly.
            </p>
        </div>
    </PaymentLayout>
</template>

<style scoped>
.page-content {
    animation: contentIn 0.4s ease-out 0.2s both;
}

.icon-wrap {
    animation: iconIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.28s both;
}

@keyframes contentIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes iconIn {
    from { opacity: 0; transform: scale(0.5); }
    to   { opacity: 1; transform: scale(1); }
}
</style>
