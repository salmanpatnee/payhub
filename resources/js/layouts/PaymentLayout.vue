<script setup lang="ts">
import { LockIcon } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
    payment?: {
        uuid?: string
        amount?: number
        currency?: string
        service?: string | null
        status?: 'pending' | 'completed' | 'failed'
    }
}>()

function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100)
}

const statusConfig = computed(() => {
    const s = props.payment?.status
    if (s === 'completed') return { label: 'Paid', cls: 'bg-green-500/20 text-green-100 border-green-400/30' }
    if (s === 'failed') return { label: 'Failed', cls: 'bg-red-500/20 text-red-100 border-red-400/30' }
    return { label: 'Pending', cls: 'bg-white/15 text-white border-white/20' }
})
</script>

<template>
    <div
        :data-brand="brand.slug"
        :style="`--brand-primary: ${brand.primary_color}; --brand-secondary: ${brand.secondary_color}`"
        class="min-h-svh flex flex-col lg:flex-row"
    >
        <!-- Sidebar -->
        <aside class="bg-[var(--brand-primary)] text-white flex-shrink-0 lg:w-80 xl:w-96 flex flex-col p-8 lg:min-h-svh">
            <!-- Logo / Brand name -->
            <div class="mb-2">
                <img
                    v-if="brand.logo_url"
                    :src="brand.logo_url"
                    :alt="brand.name"
                    class="h-10 w-auto object-contain max-w-[160px]"
                />
                <span v-else class="text-xl font-semibold tracking-tight">{{ brand.name }}</span>
            </div>

            <p v-if="brand.logo_url" class="text-white/60 text-sm mb-2">{{ brand.name }}</p>

            <!-- Payment summary -->
            <template v-if="payment">
                <div class="mt-auto">
                    <div class="border-t border-white/20 pt-6 space-y-3">
                        <div v-if="payment.uuid" class="flex items-center justify-between text-sm">
                            <span class="text-white/60">Reference</span>
                            <span class="font-mono text-xs tracking-wider">
                                {{ payment.uuid.slice(0, 8).toUpperCase() }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between text-sm">
                            <span class="text-white/60">Status</span>
                            <span
                                :class="[
                                    'text-xs px-2.5 py-0.5 rounded-full font-medium border',
                                    statusConfig.cls,
                                ]"
                            >
                                {{ statusConfig.label }}
                            </span>
                        </div>

                        <div v-if="payment.service" class="flex items-start justify-between text-sm gap-4">
                            <span class="text-white/60 shrink-0">Service</span>
                            <span class="text-right text-xs leading-relaxed">{{ payment.service }}</span>
                        </div>
                    </div>

                    <div v-if="payment.amount && payment.currency" class="mt-6 pt-6 border-t border-white/20">
                        <p class="text-white/60 text-xs mb-2 uppercase tracking-wider">Total</p>
                        <p class="text-4xl font-bold font-mono">
                            {{ formatAmount(payment.amount, payment.currency) }}
                        </p>
                    </div>
                </div>
            </template>
        </aside>

        <!-- Main content -->
        <main class="flex-1 bg-slate-50 flex flex-col items-center justify-center px-6 py-12 lg:px-12">
            <div class="w-full max-w-md">
                <slot />
            </div>

            <p class="mt-10 text-xs text-slate-400 flex items-center gap-1.5">
                <LockIcon class="size-3" />
                Secured by Stripe
            </p>
        </main>
    </div>
</template>
