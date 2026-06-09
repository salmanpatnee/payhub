<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head, useHttp } from '@inertiajs/vue3'
import { AlertCircle, LockIcon, FileText, ExternalLink } from 'lucide-vue-next'
import { StripeElements, StripeElement } from 'vue-stripe-js'
import { loadStripe } from '@stripe/stripe-js'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Spinner } from '@/components/ui/spinner'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { storeConsent } from '@/actions/App/Http/Controllers/ClientPaymentController'

type Policy = { key: string; title: string; url: string; version: string }

const props = defineProps<{
    payment: {
        uuid: string
        reference_code: number | null
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
    policies: Policy[]
}>()

const stripeLoaded  = ref(false)
const processing    = ref(false)
const errorMessage  = ref<string | null>(null)

// Policy consent — checked by default; recorded server-side before Stripe confirm.
const consent       = ref(true)
const consentError  = ref<string | undefined>(undefined)
const activePolicy  = ref<Policy | null>(null)

function policy(key: string): Policy | undefined {
    return props.policies.find((p) => p.key === key)
}

function openPolicy(key: string): void {
    const found = policy(key)
    if (found) {
        activePolicy.value = found
    }
}

function onConsentChange(): void {
    consentError.value = undefined
}

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

    // Consent gate — block before any Stripe call if unchecked.
    if (!consent.value) {
        consentError.value = 'You must agree to the Terms & Conditions, Refund Policy, and Privacy Policy before proceeding.'
        return
    }

    processing.value = true
    errorMessage.value = null

    // Record consent for the audit trail before confirming payment.
    // useHttp = standalone XHR (no Inertia visit) so entered card data is preserved.
    let consentRecorded = false
    await useHttp({ accepted: true }).post(storeConsent(props.payment.uuid).url, {
        onSuccess: () => { consentRecorded = true },
    })

    if (!consentRecorded) {
        errorMessage.value = 'We could not record your acceptance. Please try again.'
        processing.value = false
        return
    }

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
            reference_code: payment.reference_code,
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

                            <!-- Policy consent — grouped trust card -->
                            <div
                                class="rounded-xl border bg-slate-50/70 p-3.5 transition-colors"
                                :class="consentError ? 'border-red-300 bg-red-50/50' : 'border-slate-200'"
                            >
                                <div class="flex items-start gap-3">
                                    <Checkbox
                                        id="policy-consent"
                                        v-model="consent"
                                        :aria-invalid="!!consentError"
                                        class="mt-0.5 shrink-0 bg-white"
                                        @update:model-value="onConsentChange"
                                    />
                                    <Label for="policy-consent" class="text-[13px] font-normal leading-relaxed text-slate-600">
                                        I have read, understood, and agree to the Terms &amp; Conditions, Refund Policy, and Privacy Policy.
                                    </Label>
                                </div>

                                <!-- Policy documents — non-breaking chips, never split mid-name -->
                                <div class="mt-3 flex flex-wrap gap-2 pl-[1.9rem]">
                                    <button type="button" class="policy-chip" @click="openPolicy('terms')">
                                        <FileText class="size-3.5 shrink-0" />
                                        Terms &amp; Conditions
                                    </button>
                                    <button type="button" class="policy-chip" @click="openPolicy('refund')">
                                        <FileText class="size-3.5 shrink-0" />
                                        Refund Policy
                                    </button>
                                    <button type="button" class="policy-chip" @click="openPolicy('privacy')">
                                        <FileText class="size-3.5 shrink-0" />
                                        Privacy Policy
                                    </button>
                                </div>

                                <p v-if="consentError" class="mt-3 flex items-start gap-1.5 pl-[1.9rem] text-xs font-medium text-red-600">
                                    <AlertCircle class="mt-px size-3.5 shrink-0" />
                                    <span>{{ consentError }}</span>
                                </p>
                            </div>

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

            <!-- Policy document viewer — half-screen bottom sheet -->
            <Sheet :open="!!activePolicy" @update:open="(open) => { if (!open) activePolicy = null }">
                <SheetContent
                    side="bottom"
                    class="policy-sheet flex h-[88vh] flex-col gap-0 overflow-hidden rounded-t-2xl border-0 p-0 sm:h-[85vh]"
                    :style="{ '--brand-primary': brand.primary_color }"
                >
                    <SheetHeader class="policy-header relative flex-row items-center justify-start gap-3 overflow-hidden px-5 py-3 text-white sm:px-6">
                        <SheetTitle class="sr-only">{{ activePolicy?.title }}</SheetTitle>
                        <a
                            v-if="activePolicy"
                            :href="activePolicy.url"
                            target="_blank"
                            rel="noopener"
                            class="relative z-10 inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-white/80 ring-1 ring-white/25 ring-inset transition-colors hover:bg-white/15 hover:text-white"
                        >
                            <ExternalLink class="size-3.5" />
                            <span>Open in new tab</span>
                        </a>
                    </SheetHeader>
                    <div class="relative flex-1 bg-slate-100">
                        <iframe
                            v-if="activePolicy"
                            :src="activePolicy.url"
                            :title="activePolicy.title"
                            class="absolute inset-0 size-full border-0"
                        />
                    </div>
                </SheetContent>
            </Sheet>
        </div>
    </PaymentLayout>
</template>

<style scoped>
.policy-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    white-space: nowrap;
    border-radius: 0.5rem;
    border: 1px solid hsl(0, 0%, 90%);
    background: #fff;
    padding: 0.3125rem 0.625rem;
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1;
    color: hsl(215, 16%, 38%);
    transition: color 0.15s ease, border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
}
.policy-chip:hover {
    color: var(--brand-primary);
    border-color: color-mix(in srgb, var(--brand-primary) 45%, transparent);
    background: color-mix(in srgb, var(--brand-primary) 6%, #fff);
}
.policy-chip:focus-visible {
    outline: none;
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-primary) 22%, transparent);
}

/* Brand-gradient policy modal header — mirrors the sidebar treatment */
.policy-header {
    background:
        radial-gradient(ellipse 90% 130% at 0% 0%, rgba(255, 255, 255, 0.18) 0%, transparent 70%),
        radial-gradient(ellipse 75% 130% at 100% 100%, rgba(0, 0, 0, 0.25) 0%, transparent 70%),
        var(--brand-primary);
}
.policy-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
    background-size: 20px 20px;
    pointer-events: none;
    z-index: 0;
}

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

<!-- Global: the Sheet portals to <body>, so the built-in close button (direct child of
     .policy-sheet) is recolored white here to read on the brand-gradient header. -->
<style>
.policy-sheet > button {
    color: #fff;
    opacity: 0.7;
    z-index: 20;
    transition: opacity 0.15s ease;
}
.policy-sheet > button:hover {
    opacity: 1;
}
.policy-sheet > button:focus-visible {
    opacity: 1;
    outline: 2px solid rgba(255, 255, 255, 0.7);
    outline-offset: 2px;
}
</style>
