<script setup lang="ts">
import { AlertCircle, LockIcon } from 'lucide-vue-next'
import { ref, onMounted } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'

const props = defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
    }
    cloverAccount: {
        merchant_id: string
        api_access_key: string
        environment: 'sandbox' | 'production'
    }
    // Records consent for the audit trail; resolves false (and the parent shows why)
    // if consent is missing or couldn't be recorded. Called before any card token is
    // created so a charge never fires without it.
    beforeCharge: () => Promise<boolean>
}>()

const emit = defineEmits<{
    unknown: []
}>()

const sdkLoaded    = ref(false)
const processing   = ref(false)
const errorMessage = ref<string | null>(null)

// Per-field invalid state, driven by Clover's own 'change' events — lets each
// field's wrapper show its own red ring instead of a single shared error state.
const fieldInvalid = ref({
    cardNumber: false,
    cardDate: false,
    cardCvv: false,
    cardPostalCode: false,
})

let clover: any = null

// Clover's docs show the 'change' event carrying either the field's own
// { error, touched } directly, or that shape nested under its field-type key
// (e.g. { CARD_NUMBER: { error, touched } }) — handle both shapes defensively.
function isFieldInvalid(fieldType: string, event: any): boolean {
    const state = event?.[fieldType] ?? event

    return !!(state?.error && state?.touched)
}

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

// Load the environment-correct Hosted Iframe SDK. Resolves false if the CDN fails (WR-01 analog).
function loadCloverSdk(environment: string): Promise<boolean> {
    return new Promise((resolve) => {
        if ((window as any).Clover) {
            resolve(true)

            return
        }

        const src = environment === 'production'
            ? 'https://checkout.clover.com/sdk.js'
            : 'https://checkout.sandbox.dev.clover.com/sdk.js'
        const script = document.createElement('script')
        script.src = src
        script.onload = () => resolve(true)
        script.onerror = () => resolve(false)
        document.head.appendChild(script)
    })
}

onMounted(async () => {
    const loaded = await loadCloverSdk(props.cloverAccount.environment)
    const Clover = (window as any).Clover

    // WR-02 analog: null-guard the SDK before use
    if (!loaded || !Clover) {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'

        return
    }

    try {
        // These two flags are read by clover.elements() below and switch off the
        // "Secure Payments Powered by Clover / Privacy Policy" bar the SDK would
        // otherwise append to document.body, outside this app's layout.
        clover = new Clover(props.cloverAccount.api_access_key, {
            merchantId: props.cloverAccount.merchant_id,
            showSecurePayments: false,
            showPrivacyPolicy: false,
        })
        const elements = clover.elements()

        // Explicit font stack, not 'inherit' — these fields render in a cross-origin
        // iframe, which can't inherit font-family from our page's <body>.
        const styles = {
            body: {
                fontFamily: '"Instrument Sans", ui-sans-serif, system-ui, sans-serif',
            },
            input: {
                fontSize: '15px',
                fontFamily: '"Instrument Sans", ui-sans-serif, system-ui, sans-serif',
                color: '#0f172a',
            },
        }

        const cardNumber = elements.create('CARD_NUMBER', styles)
        const cardDate = elements.create('CARD_DATE', styles)
        const cardCvv = elements.create('CARD_CVV', styles)
        const cardPostalCode = elements.create('CARD_POSTAL_CODE', styles)

        cardNumber.addEventListener('change', (event: any) => {
            fieldInvalid.value.cardNumber = isFieldInvalid('CARD_NUMBER', event)
        })
        cardDate.addEventListener('change', (event: any) => {
            fieldInvalid.value.cardDate = isFieldInvalid('CARD_DATE', event)
        })
        cardCvv.addEventListener('change', (event: any) => {
            fieldInvalid.value.cardCvv = isFieldInvalid('CARD_CVV', event)
        })
        cardPostalCode.addEventListener('change', (event: any) => {
            fieldInvalid.value.cardPostalCode = isFieldInvalid('CARD_POSTAL_CODE', event)
        })

        cardNumber.mount('#clv-card-number')
        cardDate.mount('#clv-card-date')
        cardCvv.mount('#clv-card-cvv')
        cardPostalCode.mount('#clv-card-postal-code')

        sdkLoaded.value = true
    } catch (e) {
        console.error('[Clover] init failed:', e)
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
    }
})

async function submit(): Promise<void> {
    if (!clover) {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'

        return
    }

    processing.value = true
    errorMessage.value = null

    const consented = await props.beforeCharge()

    if (!consented) {
        processing.value = false

        return
    }

    try {
        const result = await clover.createToken()

        if (result.errors) {
            errorMessage.value = Object.values(result.errors as Record<string, string>)[0]
                ?? 'Please check your card details and try again.'
            processing.value = false

            return
        }

        // NEVER write DB status here — the server classifies Clover's own synchronous
        // response and writes status; this only reacts to what it reports back.
        const response = await fetch(`/pay/${props.payment.uuid}/clover/charge`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ token: result.token }),
        })

        const data = await response.json().catch(() => ({}))

        if (response.status === 429) {
            errorMessage.value = data.error ?? 'Too many attempts. Please wait a while and try again.'
            processing.value = false

            return
        }

        if (!response.ok) {
            errorMessage.value = data.error ?? 'Your payment could not be processed. Please try again.'
            processing.value = false

            return
        }

        if (data.outcome === 'approved') {
            window.location.href = `/pay/${props.payment.uuid}/success`

            return
        }

        if (data.outcome === 'declined') {
            errorMessage.value = data.message ?? 'Your card was declined. Please try a different card.'
            processing.value = false

            return
        }

        // 'unknown' — the synchronous response didn't resolve cleanly; hand off to the
        // parent page's processing/poll state rather than trying again here.
        emit('unknown')
    } catch {
        errorMessage.value = 'An unexpected error occurred. Please try again.'
        processing.value = false
    }
}
</script>

<template>
    <div class="space-y-6">
        <!-- Loading skeleton — shown until the SDK mounts the card fields. -->
        <div v-if="!sdkLoaded && !errorMessage" class="space-y-3">
            <div class="skeleton-row h-11 rounded-lg"></div>
            <div class="grid grid-cols-3 gap-3">
                <div class="skeleton-row h-11 rounded-lg"></div>
                <div class="skeleton-row h-11 rounded-lg"></div>
                <div class="skeleton-row h-11 rounded-lg"></div>
            </div>
        </div>

        <!-- Fatal error before the card fields could render -->
        <Alert v-if="!sdkLoaded && errorMessage" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertDescription>{{ errorMessage }}</AlertDescription>
        </Alert>

        <!-- The clv-card-* containers must always exist in the DOM so mount() can find them. -->
        <form v-show="sdkLoaded" @submit.prevent="submit" class="space-y-4">
            <div
                class="clv-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                :class="fieldInvalid.cardNumber ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
            >
                <div id="clv-card-number" class="clv-field"></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div
                    class="clv-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.cardDate ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <div id="clv-card-date" class="clv-field"></div>
                </div>
                <div
                    class="clv-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.cardCvv ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <div id="clv-card-cvv" class="clv-field"></div>
                </div>
                <div
                    class="clv-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.cardPostalCode ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <div id="clv-card-postal-code" class="clv-field"></div>
                </div>
            </div>

            <Alert v-if="sdkLoaded && errorMessage" variant="destructive">
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

            <p class="flex items-center justify-center gap-1.5 text-xs text-slate-600 text-center leading-relaxed">
                <LockIcon class="size-3 shrink-0" />
                Your card details are never stored · 256-bit SSL
            </p>
        </form>
    </div>
</template>

<style scoped>
.clv-field-wrap {
    display: flex;
    align-items: center;
    min-height: 44px;
}

/* Clover's iframe fills this node (height:100%), and the input inside it is
   height:1.2em anchored to the top of the frame — so sizing this to the input's
   own height (1.2 × the 15px fontSize above) and centring it with the flex
   wrapper is what actually centres the text. Stretching it to 44px does not. */
.clv-field {
    flex: 1;
    height: 18px;
}

/* Focus ring on the wrapper when its mounted Clover iframe has focus — native
   focus-within behavior propagates across the iframe boundary, so this needs
   no JS from Clover's side (unlike the invalid-state ring, which does). */
.clv-field-wrap:focus-within {
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-primary) 15%, transparent);
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

<!-- Unscoped: the node below is appended to document.body by Clover's SDK, where
     Vue's scoped data-v attribute never reaches. -->
<style>
/* showSecurePayments/showPrivacyPolicy are both off, so the footer has no
   contents — but Clover's renderFooter() ends with
   `n.hasChildNodes && document.body.appendChild(n)` (no parens, so always
   truthy) and appends the empty container anyway, whose inline padding and
   background paint a stray grey bar at the end of the page. */
.clover-footer {
    display: none !important;
}
</style>
