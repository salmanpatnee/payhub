<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { CheckCircle2 } from 'lucide-vue-next'
import PaymentLayout from '@/layouts/PaymentLayout.vue'

const props = defineProps<{
    payment: {
        amount: number
        currency: string
        service: string | null
    }
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
}>()

function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100)
}
</script>

<template>
    <PaymentLayout
        :brand="props.brand"
        :payment="{
            amount: payment.amount,
            currency: payment.currency,
            service: payment.service,
            status: 'completed',
        }"
    >
        <Head :title="`Payment received — ${props.brand.name}`" />

        <div class="space-y-6">
            <!-- Icon + heading -->
            <div class="text-center space-y-3">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-green-100 mx-auto">
                    <CheckCircle2 class="size-8 text-green-600" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Payment Successful!</h1>
                    <p class="text-sm text-slate-500 mt-1">Your payment has been processed successfully.</p>
                </div>
            </div>

            <!-- Detail rows -->
            <div class="rounded-xl border border-slate-200 bg-white divide-y divide-slate-100">
                <div class="flex items-center justify-between px-5 py-3.5">
                    <span class="text-sm text-slate-500">Status</span>
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-green-700 bg-green-50 px-2.5 py-1 rounded-full border border-green-200">
                        <CheckCircle2 class="size-3" />
                        Paid
                    </span>
                </div>
                <div class="flex items-center justify-between px-5 py-3.5">
                    <span class="text-sm text-slate-500">Amount</span>
                    <span class="text-sm font-semibold font-mono text-slate-900">
                        {{ formatAmount(payment.amount, payment.currency) }}
                    </span>
                </div>
                <div v-if="payment.service" class="flex items-center justify-between px-5 py-3.5">
                    <span class="text-sm text-slate-500">Service</span>
                    <span class="text-sm text-slate-700">{{ payment.service }}</span>
                </div>
            </div>
        </div>
    </PaymentLayout>
</template>
