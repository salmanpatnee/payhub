<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import { AlertCircle, LockIcon } from 'lucide-vue-next'
import { StripeElements, StripeElement } from 'vue-stripe-js'
import { loadStripe } from '@stripe/stripe-js'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
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

// WR-01: Check return value — loadStripe() returns null if Stripe.js CDN fails to load
onMounted(async () => {
    const stripe = await loadStripe(props.stripeAccount.publishable_key)
    if (stripe !== null) {
        stripeLoaded.value = true
    }
})

function formatAmount(cents: number, currency: string): string {
    const formatter = new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    )
    return formatter.formatToParts(cents / 100).map((part, i, parts) => {
        if (part.type === 'currency' && parts[i + 1]?.type !== 'literal') {
            return part.value + ' '
        }
        return part.value
    }).join('')
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

// NEVER write DB status here — all payment status comes from webhooks only
// SEC-04: return_url never contains client_secret
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
            package: payment.package,
            status: 'pending',
        }"
    >
        <Head :title="`Pay ${props.brand.name}`" />

        <div class="form-content space-y-6">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Complete your payment</h1>
                <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                    Enter your card details below to pay securely.
                </p>
            </div>

            <!-- Stripe Elements — gated on stripeLoaded -->
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
                                class="w-full bg-[var(--brand-primary)] text-white hover:bg-[var(--brand-primary)]/90 focus-visible:ring-[var(--brand-primary)]/50 font-semibold tracking-wide cursor-pointer"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4 mr-2" />
                                <span>{{ processing ? 'Processing…' : `Pay ${formatAmount(payment.amount, payment.currency)}` }}</span>
                            </Button>

                            <!-- Security micro-copy -->
                            <p class="flex items-center justify-center gap-1.5 text-xs text-slate-600 text-center leading-relaxed">
                                <LockIcon class="size-3 shrink-0" />
                                Your card details are never stored · 256-bit SSL
                            </p>
                        </form>
                    </template>
                </StripeElements>
            </template>

            <!-- Loading skeleton -->
            <template v-else>
                <div class="space-y-3">
                    <div class="skeleton-row h-12 rounded-lg"></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="skeleton-row h-12 rounded-lg"></div>
                        <div class="skeleton-row h-12 rounded-lg"></div>
                    </div>
                    <div class="skeleton-row h-12 rounded-lg"></div>
                    <div class="skeleton-row h-12 rounded-lg mt-2 opacity-75"></div>
                </div>
            </template>
        </div>
    </PaymentLayout>
</template>

<style scoped>
.form-content {
    animation: contentIn 0.4s ease-out 0.22s both;
}

@keyframes contentIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

.skeleton-row {
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s ease-in-out infinite;
}

@keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>
