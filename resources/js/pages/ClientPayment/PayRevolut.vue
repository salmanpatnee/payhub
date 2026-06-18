<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Head, useHttp } from '@inertiajs/vue3'
import { AlertCircle, CreditCard, LockIcon, X } from 'lucide-vue-next'
import RevolutCheckout from '@revolut/checkout'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogTitle, DialogClose, DialogDescription } from '@/components/ui/dialog'
import { storeConsent } from '@/actions/App/Http/Controllers/ClientPaymentController'

type Policy = { key: string; title: string; version: string; html: string }

const props = defineProps<{
    payment: {
        uuid: string
        reference_code: string | null
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
    revolutAccount: { public_key: string | null }
    orderToken: string
    mode: 'sandbox' | 'prod'
    customerEmail: string | null
    customerName: string | null
    policies: Policy[]
}>()

const email          = ref(props.customerEmail ?? '')
// Only prefill the cardholder name when it is already a full name (first + last).
// Revolut rejects single-word names, so prefilling e.g. "Leo" would show a value
// that fails on submit — leave the field empty and let the placeholder guide them.
const prefillName     = (props.customerName ?? '').trim()
const cardholderName  = ref(prefillName.split(/\s+/).filter(Boolean).length >= 2 ? prefillName : '')
const cardLoaded      = ref(false)
const cardComplete = ref(false)
const processing   = ref(false)
const errorMessage = ref<string | null>(null)

const cardFieldEl = ref<HTMLElement | null>(null)
// The Revolut Card Field instance (has submit()/validate()); created on mount.
let cardField: { submit: (meta?: Record<string, unknown>) => void } | null = null

// Policy consent — checked by default; recorded server-side before submit.
const consent       = ref(true)
const consentError  = ref<string | undefined>(undefined)
const activePolicy  = ref<Policy | null>(null)
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

function blockCopyKeys(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && ['c', 'a', 'x', 's', 'p'].includes(event.key.toLowerCase())) {
        event.preventDefault()
    }
}

// Mount the embedded Card Field. No redirect: card data is entered inline and the
// SDK renders any 3DS challenge itself. DB status is NEVER set here — the
// ORDER_COMPLETED webhook is the sole source of truth.
onMounted(async () => {
    try {
        const instance = await RevolutCheckout(props.orderToken, props.mode)

        // The card inputs render inside a FIXED-HEIGHT cross-origin iframe (~21px),
        // so box chrome (border/padding/radius/focus-ring) must NOT go here — adding
        // padding clips the text. Style only the inner TEXT here so it matches the
        // shadcn name/email inputs; the visible box + focus/invalid ring live on the
        // wrapper div via the .card-field-box CSS below (Revolut toggles
        // .rc-card-field--focused / --invalid classes on that wrapper).
        cardField = instance.createCardField({
            target: cardFieldEl.value as HTMLElement,
            locale: 'en',
            theme: 'light',
            styles: {
                default: {
                    color: 'hsl(0, 0%, 3.9%)',
                    fontFamily: '"Instrument Sans", ui-sans-serif, system-ui, sans-serif',
                    fontSize: '14px',
                },
                invalid: {
                    color: 'hsl(0, 84.2%, 60.2%)',
                },
            },
            onValidation: (errors: unknown[]) => {
                cardComplete.value = errors.length === 0
            },
            onSuccess: () => {
                // Truth still comes from the webhook; this is UX only.
                window.location.href = `${window.location.origin}/pay/${props.payment.uuid}/success`
            },
            onError: (error: { message?: string }) => {
                errorMessage.value = error?.message ?? 'An unexpected error occurred. Please try again.'
                processing.value = false
            },
        })

        cardLoaded.value = true
    } catch {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
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

async function submit(): Promise<void> {
    if (!cardField) {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
        return
    }

    // Revolut requires a valid customer email + a full cardholder name on submit.
    const customerEmail = email.value.trim()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerEmail)) {
        errorMessage.value = 'Please enter a valid email address.'
        return
    }

    const customerName = cardholderName.value.trim()
    if (customerName.split(/\s+/).filter(Boolean).length < 2) {
        errorMessage.value = 'Please enter the cardholder’s full name (first and last).'
        return
    }

    // Consent gate — block before any payment call if unchecked.
    if (!consent.value) {
        consentError.value = 'You must agree to the Terms & Conditions, Refund Policy, and Privacy Policy before proceeding.'
        return
    }

    processing.value = true
    errorMessage.value = null

    // Record consent for the audit trail before submitting the card.
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

    // Resolves via the onSuccess / onError callbacks registered at creation.
    cardField.submit({ email: customerEmail, name: customerName })
}
</script>

<template>
    <PaymentLayout
        :brand="props.brand"
        provider="revolut"
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

            <form @submit.prevent="submit" class="space-y-5">
                <!-- Stripe-style payment panel: one white rounded container holding all
                     card inputs, mirroring the Stripe Payment Element's card box. -->
                <div
                    class="card-panel"
                    :style="{ '--brand-primary': brand.primary_color || '#000000' }"
                >
                    <div class="card-panel-header">
                        <CreditCard class="size-4" />
                        <span>Card</span>
                    </div>

                    <!-- Cardholder name + email — required by Revolut on submit; prefilled when known -->
                    <div class="grid gap-2">
                        <Label for="cardholder-name" class="text-sm text-slate-700">Cardholder name</Label>
                        <Input
                            id="cardholder-name"
                            v-model="cardholderName"
                            type="text"
                            placeholder="Jane Smith"
                            autocomplete="cc-name"
                            class="h-11"
                            required
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email" class="text-sm text-slate-700">Email</Label>
                        <Input
                            id="email"
                            v-model="email"
                            type="email"
                            placeholder="you@example.com"
                            autocomplete="email"
                            class="h-11"
                            required
                        />
                    </div>

                    <!-- Card details. Revolut's embedded Card Field is a single combined
                         widget (number · expiry · CVV); box chrome + focus/invalid ring
                         live on this wrapper, the iframe inside only carries the text.
                         The box stays visible (never display:none) so Revolut measures a
                         non-zero width at mount — hiding it clips the placeholders. A
                         shimmer overlays it until the field paints. -->
                    <div class="grid gap-2">
                        <Label class="text-sm text-slate-700">Card details</Label>
                        <div class="relative">
                            <div
                                v-if="!cardLoaded"
                                class="skeleton-row absolute inset-0 z-10 rounded-md"
                            ></div>
                            <div ref="cardFieldEl" class="card-field-box"></div>
                        </div>
                    </div>
                </div>

                <Alert v-if="errorMessage" variant="destructive">
                    <AlertCircle class="size-4" />
                    <AlertDescription>{{ errorMessage }}</AlertDescription>
                </Alert>

                <!-- Primary action -->
                <Button
                    type="submit"
                    size="lg"
                    class="w-full bg-[var(--brand-primary)] text-white hover:bg-[var(--brand-primary)]/90 focus-visible:ring-[var(--brand-primary)]/50 font-semibold tracking-wide cursor-pointer"
                    :disabled="processing || !cardLoaded"
                >
                    <span>{{ processing ? 'Processing…' : `Pay ${formatAmount(payment.amount, payment.currency)}` }}</span>
                </Button>

                <!-- Security reassurance -->
                <p class="flex items-center justify-center gap-1.5 text-xs text-slate-600 text-center leading-relaxed">
                    <LockIcon class="size-3 shrink-0" />
                    Your card details are never stored · 256-bit SSL
                </p>

                <!-- Compliance footer — consent + policy links -->
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

            <!-- Policy viewer — Material 3 / Google-style dialog. Native HTML, read-only. -->
            <Dialog :open="!!activePolicy" @update:open="(open) => { if (!open) activePolicy = null }">
                <DialogContent
                    :show-close-button="false"
                    class="policy-dialog flex max-h-[72vh] w-full max-w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden rounded-2xl border-0 p-0 sm:max-w-lg"
                    :style="{ '--brand-primary': brand.primary_color }"
                >
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
/* Stripe-style payment panel — mirrors the white rounded card box the Stripe Payment
   Element renders, so both providers' pay pages look the same: white surface, hairline
   border, 12px radius, restrained shadow, with a brand-colored "Card" header. */
.card-panel {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.25rem;
    background: #fff;
    border: 1px solid hsl(0, 0%, 92.8%);
    border-radius: 12px;
    box-shadow:
        0 2px 4px -2px rgb(15 23 42 / 0.06),
        0 1px 2px -1px rgb(15 23 42 / 0.04);
}
.card-panel-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--brand-primary);
}

/* Revolut Card Field wrapper. The card inputs sit in a fixed-height iframe, so the
   visible "input box" (border/radius/padding + brand focus ring) is rendered here to
   match the shadcn Input above it (border hsl(0,0%,92.8%), radius 6px, h-9). Revolut
   toggles .rc-card-field--focused / --invalid on this element for interactive states. */
.card-field-box {
    display: flex;
    align-items: center;
    min-height: 2.75rem; /* 44px — matches Stripe's input height */
    padding: 0 0.75rem; /* 12px horizontal; flex centers the iframe vertically */
    border: 1px solid hsl(0, 0%, 92.8%);
    border-radius: 6px;
    background: #fff;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); /* shadow-xs */
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.card-field-box :deep(iframe) {
    width: 100%;
}
.card-field-box.rc-card-field--focused {
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-primary) 20%, transparent);
}
.card-field-box.rc-card-field--invalid {
    border-color: hsl(0, 84.2%, 60.2%);
}

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

.policy-dialog {
    border-radius: 1rem !important;
    border: 1px solid hsl(214 20% 90%) !important;
    box-shadow:
        0 12px 32px -12px rgba(15, 23, 42, 0.18),
        0 2px 6px -2px rgba(15, 23, 42, 0.08) !important;
}

.policy-header-elevated {
    box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.16);
}

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
