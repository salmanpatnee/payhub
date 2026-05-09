<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import { AlertCircle } from 'lucide-vue-next'
import { StripeElements, StripeElement } from 'vue-stripe-js'
import { loadStripe } from '@stripe/stripe-js'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
    Card, CardContent, CardDescription,
    CardFooter, CardHeader, CardTitle,
} from '@/components/ui/card'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Separator } from '@/components/ui/separator'
import { Spinner } from '@/components/ui/spinner'

const props = defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
        service: string | null
        package: string | null
    }
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
    stripeAccount: { publishable_key: string }
    clientSecret: string
}>()

const stripeLoaded  = ref(false)
const processing    = ref(false)
const errorMessage  = ref<string | null>(null)

// Call loadStripe() in onMounted — sets window.Stripe which vue-stripe-js requires
// Only after this resolves should StripeElements mount
onMounted(async () => {
    // WR-01: Check return value — loadStripe() returns null if Stripe.js CDN fails to load
    // (network error, ad-blocker). Only set stripeLoaded=true when Stripe is available.
    const stripe = await loadStripe(props.stripeAccount.publishable_key)
    if (stripe !== null) {
        stripeLoaded.value = true
    }
    // If stripe is null, stripeLoaded remains false → loading skeleton stays visible
    // and the StripeElements component never mounts, preventing unhandled runtime errors.
})

// formatAmount: copies pattern from resources/js/pages/payments/Show.vue lines 61-66
function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100)
}

// Stripe Elements appearance — colorPrimary read at compute time (after layout CSS vars are mounted)
// Fallback chain: CSS var → prop value → '#000000' (UI-SPEC.md Stripe Elements section)
const elementsOptions = computed(() => ({
    clientSecret: props.clientSecret,
    appearance: {
        theme: 'stripe' as const,
        variables: {
            colorPrimary: getComputedStyle(document.documentElement)
                .getPropertyValue('--brand-primary').trim()
                || props.brand.primary_color
                || '#000000',
            colorBackground: '#ffffff',
            colorText: 'hsl(0, 0%, 3.9%)',
            colorDanger: 'hsl(0, 84.2%, 60.2%)',
            fontFamily: '"Instrument Sans", ui-sans-serif, system-ui, sans-serif',
            borderRadius: '6px',
            spacingUnit: '4px',
        },
        rules: {
            '.Input': {
                border: '1px solid hsl(0, 0%, 92.8%)',
                boxShadow: 'none',
            },
            '.Input:focus': {
                border: '1px solid var(--brand-primary)',
                boxShadow: '0 0 0 3px color-mix(in srgb, var(--brand-primary) 20%, transparent)',
            },
            '.Label': {
                fontSize: '14px',
                fontWeight: '400',
                color: 'hsl(0, 0%, 3.9%)',
            },
            '.Error': {
                fontSize: '14px',
            },
        },
    },
}))

// confirmPayment — NEVER write DB status here (CLAUDE.md rule — Phase 6 webhooks only)
// SEC-04: return_url is /pay/{uuid}/success — client_secret is NOT in the URL
// Stripe appends payment_intent_client_secret to the return_url; the success controller discards it
async function submit(instance: any, elements: any): Promise<void> {
    // WR-02: Guard against null instance/elements — possible if Stripe failed to initialise
    // (should not occur when stripeLoaded gate is working, but defensive programming)
    if (!instance || !elements) {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
        return
    }

    processing.value = true
    errorMessage.value = null

    const { error } = await instance.confirmPayment({
        elements,
        confirmParams: {
            return_url: `${window.location.origin}/pay/${props.payment.uuid}/success`,
        },
    })

    if (error) {
        // Use Stripe's error.message verbatim for card declines — do not rewrite it
        errorMessage.value = error.message ?? 'An unexpected error occurred. Please try again.'
        processing.value = false
        // Note: processing stays true on success because Stripe redirects the browser
    }
}
</script>

<template>
    <PaymentLayout :brand="props.brand">
        <Head :title="`Pay ${props.brand.name}`" />

        <Card class="rounded-xl shadow-sm">
            <CardHeader>
                <CardTitle class="text-xl">Complete your payment</CardTitle>
                <CardDescription>Review your order and enter payment details below.</CardDescription>
            </CardHeader>

            <CardContent class="space-y-6">
                <!-- Payment summary block (UI-SPEC.md prescriptive Tailwind) -->
                <div class="rounded-lg border border-border bg-[--color-brand-secondary]/10 px-6 py-4">
                    <div class="flex items-baseline gap-3">
                        <span class="text-3xl font-semibold font-mono">
                            {{ formatAmount(payment.amount, payment.currency) }}
                        </span>
                        <Badge variant="outline" class="text-xs uppercase tracking-wide">
                            {{ payment.currency.toUpperCase() }}
                        </Badge>
                    </div>
                    <div class="mt-2 space-y-1 text-sm text-muted-foreground">
                        <p v-if="payment.service">{{ payment.service }}</p>
                        <p v-if="payment.package" class="capitalize">{{ payment.package }} package</p>
                    </div>
                </div>

                <Separator />

                <!-- Stripe Elements — conditional on stripeLoaded gate -->
                <template v-if="stripeLoaded">
                    <StripeElements
                        :stripe-key="stripeAccount.publishable_key"
                        :elements-options="elementsOptions"
                    >
                        <template #default="{ instance, elements }">
                            <form @submit.prevent="submit(instance, elements)">
                                <!-- StripeElement type="payment" mounts the PaymentElement inline -->
                                <StripeElement type="payment" :elements="elements" />

                                <!-- Error alert — shown on confirmPayment failure -->
                                <Alert v-if="errorMessage" variant="destructive" class="mt-4">
                                    <AlertCircle class="size-4" />
                                    <AlertDescription>{{ errorMessage }}</AlertDescription>
                                </Alert>

                                <CardFooter class="px-0 pt-6">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        class="w-full bg-[--color-brand-primary] text-white hover:bg-[--color-brand-primary]/90 focus-visible:ring-[--color-brand-primary]/50"
                                        :disabled="processing"
                                    >
                                        <Spinner v-if="processing" class="size-4 mr-2" />
                                        <span>{{ processing ? 'Processing...' : `Pay ${formatAmount(payment.amount, payment.currency)}` }}</span>
                                    </Button>
                                </CardFooter>
                            </form>
                        </template>
                    </StripeElements>
                </template>

                <!-- Loading skeleton — shown before Stripe.js loads (UI-SPEC.md Elements Loading State) -->
                <template v-else>
                    <div class="h-32 flex items-center justify-center">
                        <Spinner class="size-5 text-muted-foreground" />
                    </div>
                </template>
            </CardContent>
        </Card>
    </PaymentLayout>
</template>
