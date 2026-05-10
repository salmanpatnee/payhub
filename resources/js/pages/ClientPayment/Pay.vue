<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import { AlertCircle } from 'lucide-vue-next'
import { StripeElements, StripeElement } from 'vue-stripe-js'
import { loadStripe } from '@stripe/stripe-js'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { Button } from '@/components/ui/button'
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
})

function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100)
}

const elementsOptions = computed(() => ({
    clientSecret: props.clientSecret,
    appearance: {
        theme: 'stripe' as const,
        variables: {
            colorPrimary: props.brand.primary_color || '#000000',
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
                border: `1px solid ${props.brand.primary_color}`,
                boxShadow: `0 0 0 3px color-mix(in srgb, ${props.brand.primary_color} 20%, transparent)`,
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
async function submit(instance: any, elements: any): Promise<void> {
    // WR-02: Guard against null instance/elements
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
        errorMessage.value = error.message ?? 'An unexpected error occurred. Please try again.'
        processing.value = false
    }
}
</script>

<template>
    <PaymentLayout
        :brand="props.brand"
        :payment="{
            uuid: payment.uuid,
            amount: payment.amount,
            currency: payment.currency,
            service: payment.service,
            status: 'pending',
        }"
    >
        <Head :title="`Pay ${props.brand.name}`" />

        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Complete your payment</h1>
                <p class="text-sm text-slate-500 mt-1">Enter your card details below to complete this payment.</p>
            </div>

            <Separator />

            <!-- Stripe Elements — conditional on stripeLoaded gate -->
            <template v-if="stripeLoaded">
                <StripeElements
                    :stripe-key="stripeAccount.publishable_key"
                    :elements-options="elementsOptions"
                >
                    <template #default="{ instance, elements }">
                        <form @submit.prevent="submit(instance, elements)" class="space-y-5">
                            <StripeElement type="payment" :elements="elements" />

                            <Alert v-if="errorMessage" variant="destructive">
                                <AlertCircle class="size-4" />
                                <AlertDescription>{{ errorMessage }}</AlertDescription>
                            </Alert>

                            <Button
                                type="submit"
                                size="lg"
                                class="w-full bg-[var(--brand-primary)] text-white hover:bg-[var(--brand-primary)]/90 focus-visible:ring-[var(--brand-primary)]/50"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4 mr-2" />
                                <span>{{ processing ? 'Processing...' : `Pay ${formatAmount(payment.amount, payment.currency)}` }}</span>
                            </Button>
                        </form>
                    </template>
                </StripeElements>
            </template>

            <!-- Loading skeleton — shown before Stripe.js loads -->
            <template v-else>
                <div class="h-32 flex items-center justify-center">
                    <Spinner class="size-5 text-slate-400" />
                </div>
            </template>
        </div>
    </PaymentLayout>
</template>
