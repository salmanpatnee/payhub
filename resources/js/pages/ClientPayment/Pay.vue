<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head, useHttp } from '@inertiajs/vue3'
import { AlertCircle, LockIcon, X } from 'lucide-vue-next'
import { StripeElements, StripeElement } from 'vue-stripe-js'
import { loadStripe } from '@stripe/stripe-js'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Spinner } from '@/components/ui/spinner'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogTitle, DialogClose, DialogDescription } from '@/components/ui/dialog'
import { storeConsent } from '@/actions/App/Http/Controllers/ClientPaymentController'

type Policy = { key: string; title: string; version: string; html: string }

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
// Material/Google dialog: the header gains a divider + faint elevation only once
// the body is scrolled away from the top.
const policyScrolled = ref(false)

function openPolicy(key: string): void {
    const found = props.policies.find((p) => p.key === key)
    if (found) {
        policyScrolled.value = false
        activePolicy.value = found
    }
}

function onPolicyScroll(event: Event): void {
    policyScrolled.value = (event.target as HTMLElement).scrollTop > 4
}

function onConsentChange(): void {
    consentError.value = undefined
}

// Discourage casual copying of policy text. Not DRM — degrades gracefully,
// view-source still works. Scroll keys (arrows/PageUp/PageDown/Space) untouched.
function blockCopyKeys(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && ['c', 'a', 'x', 's', 'p'].includes(event.key.toLowerCase())) {
        event.preventDefault()
    }
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

                            <!-- Primary action — the sole visual focus -->
                            <Button
                                type="submit"
                                size="lg"
                                class="w-full bg-[var(--brand-primary)] text-white hover:bg-[var(--brand-primary)]/90 focus-visible:ring-[var(--brand-primary)]/50 font-semibold tracking-wide cursor-pointer"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4 mr-2" />
                                <span>{{ processing ? 'Processing…' : `Pay ${formatAmount(payment.amount, payment.currency)}` }}</span>
                            </Button>

                            <!-- Security reassurance — directly under the CTA -->
                            <p class="flex items-center justify-center gap-1.5 text-xs text-slate-600 text-center leading-relaxed">
                                <LockIcon class="size-3 shrink-0" />
                                Your card details are never stored · 256-bit SSL
                            </p>

                            <!-- Compliance footer — consent + policy links, intentionally low-emphasis boilerplate -->
                            <div class="mt-8 space-y-2.5 border-t border-slate-100 pt-5">
                                <div class="flex items-start gap-2">
                                    <Checkbox
                                        id="policy-consent"
                                        v-model="consent"
                                        :aria-invalid="!!consentError"
                                        class="mt-px size-3.5 shrink-0 data-[state=checked]:!bg-slate-400 data-[state=checked]:!border-slate-400 data-[state=checked]:!text-white"
                                        @update:model-value="onConsentChange"
                                    />
                                    <Label for="policy-consent" class="text-[11px] font-normal leading-relaxed text-slate-400">
                                        I have read, understood, and agree to the Terms &amp; Conditions, Refund Policy, and Privacy Policy.
                                    </Label>
                                </div>

                                <p v-if="consentError" class="flex items-start gap-1.5 pl-[1.35rem] text-[11px] font-medium text-red-600">
                                    <AlertCircle class="mt-px size-3 shrink-0" />
                                    <span>{{ consentError }}</span>
                                </p>

                                <div class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-[11px] text-slate-400">
                                    <button type="button" class="policy-link" @click="openPolicy('terms')">Terms &amp; Conditions</button>
                                    <span aria-hidden="true">·</span>
                                    <button type="button" class="policy-link" @click="openPolicy('refund')">Refund Policy</button>
                                    <span aria-hidden="true">·</span>
                                    <button type="button" class="policy-link" @click="openPolicy('privacy')">Privacy Policy</button>
                                </div>
                            </div>
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

            <!-- Policy viewer — Material 3 / Google-style dialog. Native HTML, read-only, fully legible. -->
            <Dialog :open="!!activePolicy" @update:open="(open) => { if (!open) activePolicy = null }">
                <DialogContent
                    :show-close-button="false"
                    class="policy-dialog flex max-h-[72vh] w-full max-w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden rounded-2xl border-0 p-0 sm:max-w-lg"
                    :style="{ '--brand-primary': brand.primary_color }"
                >
                    <!-- Header — persistent hairline divider; scroll adds a faint shadow only -->
                    <div
                        class="relative z-10 flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5 transition-shadow duration-200"
                        :class="policyScrolled ? 'policy-header-elevated' : ''"
                    >
                        <DialogTitle class="text-[17px] font-semibold leading-snug tracking-[-0.01em] text-slate-900">
                            {{ activePolicy?.title }}
                        </DialogTitle>
                        <DialogClose class="-mr-1 flex size-8 shrink-0 items-center justify-center rounded-md text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-300">
                            <X class="size-4" />
                            <span class="sr-only">Close</span>
                        </DialogClose>
                    </div>
                    <DialogDescription class="sr-only">{{ activePolicy?.title }} — policy document</DialogDescription>

                    <!-- Scrollable body — copy-discouraged, but text stays fully readable -->
                    <div
                        tabindex="0"
                        class="policy-prose flex-1 overflow-y-auto px-5 pb-6 pt-4 sm:px-6"
                        @scroll="onPolicyScroll"
                        @copy.prevent
                        @cut.prevent
                        @contextmenu.prevent
                        @dragstart.prevent
                        @keydown="blockCopyKeys"
                        v-html="activePolicy?.html"
                    />
                </DialogContent>
            </Dialog>
        </div>
    </PaymentLayout>
</template>

<style scoped>
.policy-link {
    color: inherit;
    cursor: pointer;
    border-radius: 0.25rem;
    text-underline-offset: 2px;
    transition: color 0.15s ease;
}
.policy-link:hover {
    color: hsl(215, 16%, 38%);
    text-decoration: underline;
}
.policy-link:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-primary) 35%, transparent);
}

/* Refined enterprise-SaaS surface — hairline border + restrained shadow. */
.policy-dialog {
    border-radius: 1rem !important;
    border: 1px solid hsl(214 20% 90%) !important;
    box-shadow:
        0 12px 32px -12px rgba(15, 23, 42, 0.18),
        0 2px 6px -2px rgba(15, 23, 42, 0.08) !important;
}

/* Persistent divider lives on the header element; scroll only adds a faint shadow. */
.policy-header-elevated {
    box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.16);
}

/* Native long-form policy typography. v-html injects unscoped markup, so :deep() is required. */
.policy-prose {
    user-select: none;
    -webkit-user-select: none;
    -ms-user-select: none;
    color: hsl(215, 19%, 35%);
    font-size: 0.9rem;
    line-height: 1.72;
}
.policy-prose:focus-visible {
    outline: none;
}
/* The markdown's leading "# Title" duplicates the dialog header — hide it. */
.policy-prose :deep(h1) {
    display: none;
}
.policy-prose :deep(h2) {
    font-size: 0.95rem;
    font-weight: 500;
    color: hsl(222, 30%, 18%);
    margin-top: 1.6rem;
    margin-bottom: 0.5rem;
}
.policy-prose :deep(h2:first-of-type) {
    margin-top: 0.25rem;
}
.policy-prose :deep(p) {
    margin-bottom: 0.85rem;
}
.policy-prose :deep(ul),
.policy-prose :deep(ol) {
    margin: 0.5rem 0 1rem;
    padding-left: 1.35rem;
}
.policy-prose :deep(ul) {
    list-style: disc;
}
.policy-prose :deep(ol) {
    list-style: decimal;
}
.policy-prose :deep(li) {
    margin-bottom: 0.4rem;
    padding-left: 0.15rem;
}
.policy-prose :deep(li::marker) {
    color: color-mix(in srgb, var(--brand-primary) 55%, hsl(215, 16%, 60%));
}
.policy-prose :deep(strong) {
    font-weight: 600;
    color: hsl(222, 47%, 20%);
}
.policy-prose :deep(a) {
    color: var(--brand-primary);
    text-decoration: underline;
    text-underline-offset: 2px;
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
