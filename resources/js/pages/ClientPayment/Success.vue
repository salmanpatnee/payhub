<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { CheckCircle2 } from 'lucide-vue-next'
import {
    Card, CardContent, CardHeader, CardTitle, CardDescription,
} from '@/components/ui/card'
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

// formatAmount: identical to Show.vue lines 61-66 pattern
function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100)
}
</script>

<template>
    <PaymentLayout :brand="props.brand">
        <Head :title="`Payment received — ${props.brand.name}`" />

        <Card class="rounded-xl shadow-sm text-center">
            <CardHeader class="pb-0">
                <!-- Success icon block (UI-SPEC.md Success.vue icon section) -->
                <div class="flex flex-col items-center gap-2 py-2">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-green-50">
                        <CheckCircle2 class="size-7 text-green-600" />
                    </div>
                </div>
                <CardTitle class="text-xl">Payment received</CardTitle>
                <CardDescription>Thank you — your payment has been processed successfully.</CardDescription>
            </CardHeader>

            <CardContent class="pt-6 space-y-4">
                <!-- Amount — text-xl font-semibold font-mono (UI-SPEC.md Success.vue) -->
                <p class="text-xl font-semibold font-mono">
                    {{ formatAmount(payment.amount, payment.currency) }}
                </p>
                <!-- Service line — D-06: shown if set, no package or note on success page -->
                <p v-if="payment.service" class="text-sm text-muted-foreground">
                    for {{ payment.service }}
                </p>
            </CardContent>
        </Card>
    </PaymentLayout>
</template>
