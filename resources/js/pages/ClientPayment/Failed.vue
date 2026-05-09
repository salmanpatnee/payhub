<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { XCircle } from 'lucide-vue-next'
import {
    Card, CardContent, CardHeader, CardTitle, CardDescription,
} from '@/components/ui/card'
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
    <PaymentLayout :brand="props.brand">
        <Head :title="`Payment unsuccessful — ${props.brand.name}`" />

        <Card class="rounded-xl shadow-sm text-center">
            <CardHeader class="pb-0">
                <!-- Error icon block (UI-SPEC.md Failed.vue) -->
                <div class="flex h-14 w-14 items-center justify-center rounded-full bg-red-50 mx-auto">
                    <XCircle class="size-7 text-destructive" />
                </div>
                <CardTitle class="text-xl">Payment unsuccessful</CardTitle>
                <CardDescription>
                    We weren't able to process your payment. Please check your card details and try again.
                </CardDescription>
            </CardHeader>

            <CardContent class="pt-6">
                <!-- D-05: "Try again" link back to /pay/{uuid} — re-opens fresh form, new PI on load -->
                <Button
                    as-child
                    size="lg"
                    class="w-full bg-[--color-brand-primary] text-white hover:bg-[--color-brand-primary]/90 focus-visible:ring-[--color-brand-primary]/50"
                >
                    <a :href="`/pay/${payment.uuid}`">Try again</a>
                </Button>
            </CardContent>
        </Card>
    </PaymentLayout>
</template>
