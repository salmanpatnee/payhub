<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { XCircle } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import PaymentLayout from '@/layouts/PaymentLayout.vue'

const props = defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
    }
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
    <PaymentLayout
        :brand="props.brand"
        :payment="{
            uuid: payment.uuid,
            amount: payment.amount,
            currency: payment.currency,
            status: 'failed',
        }"
    >
        <Head :title="`Payment unsuccessful — ${props.brand.name}`" />

        <div class="space-y-6">
            <!-- Icon + heading -->
            <div class="text-center space-y-3">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-red-100 mx-auto">
                    <XCircle class="size-8 text-red-600" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Payment Failed</h1>
                    <p class="text-sm text-slate-500 mt-1">We weren't able to process your payment.</p>
                </div>
            </div>

            <!-- Info sections -->
            <div class="grid grid-cols-2 gap-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold text-slate-700 uppercase tracking-wider mb-3">Why did this happen?</p>
                    <ul class="space-y-2 text-xs text-slate-500">
                        <li>Card was declined</li>
                        <li>Insufficient funds</li>
                        <li>Expired card details</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold text-slate-700 uppercase tracking-wider mb-3">To resolve this:</p>
                    <ul class="space-y-2 text-xs text-slate-500">
                        <li>Check your card details</li>
                        <li>Try a different card</li>
                        <li>Contact your bank</li>
                    </ul>
                </div>
            </div>

            <!-- D-05: "Try again" link back to /pay/{uuid} — re-opens fresh form, new PI on load -->
            <Button
                as-child
                size="lg"
                class="w-full bg-[var(--brand-primary)] text-white hover:bg-[var(--brand-primary)]/90 focus-visible:ring-[var(--brand-primary)]/50"
            >
                <a :href="`/pay/${payment.uuid}`">Try again</a>
            </Button>
        </div>
    </PaymentLayout>
</template>
