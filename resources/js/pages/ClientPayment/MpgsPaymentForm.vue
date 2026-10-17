<script setup lang="ts">
import { AlertCircle, LockIcon } from 'lucide-vue-next'
import { ref, onMounted } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import {
    authenticatePayer,
    captureCard,
    loadLibraries,
    mountFields,
    type MpgsFieldTargets,
    type MpgsSession,
} from '@/lib/mpgs/gateway'

const props = defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
    }
    mpgsAccount: {
        gateway_host: string
        merchant_id: string
        api_version: number
    }
    // Records consent for the audit trail; resolves false (and the parent shows why)
    // if consent is missing or couldn't be recorded. Called before any card data is
    // captured so a payment never starts without it.
    beforeCharge: () => Promise<boolean>
}>()

const emit = defineEmits<{
    unknown: []
}>()

const ready        = ref(false)
const processing   = ref(false)
const errorMessage = ref<string | null>(null)

const fieldInvalid = ref<Record<keyof MpgsFieldTargets, boolean>>({
    cardNumber: false,
    expiryMonth: false,
    expiryYear: false,
    securityCode: false,
    cardholderName: false,
})

const targets: MpgsFieldTargets = {
    cardNumber: '#mpgs-card-number',
    expiryMonth: '#mpgs-expiry-month',
    expiryYear: '#mpgs-expiry-year',
    securityCode: '#mpgs-security-code',
    cardholderName: '#mpgs-cardholder-name',
}

// The latest server issued session. Replaced on every prepare(), so a declined
// card retries on a fresh session and a fresh order id.
let session: MpgsSession | null = null

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

function xsrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    }
}

/**
 * Ask the server for a new session (it writes amount and currency from the
 * Payment record onto it) and attach the hosted fields to it. Returns false and
 * sets errorMessage when the processor could not be reached.
 */
async function prepare(): Promise<boolean> {
    const response = await fetch(`/pay/${props.payment.uuid}/mpgs/session`, {
        method: 'POST',
        headers: xsrfHeaders(),
    })
    const data = await response.json().catch(() => ({}))

    if (!response.ok) {
        errorMessage.value = response.status === 429
            ? (data.error ?? 'Too many attempts. Please wait a while and try again.')
            : (data.error ?? 'Your payment could not be processed. Please try again.')

        return false
    }

    session = data as MpgsSession

    await mountFields(session, targets, {
        onFieldInvalid: (field, invalid) => { fieldInvalid.value[field] = invalid },
    })

    return true
}

onMounted(async () => {
    try {
        await loadLibraries({
            gatewayHost: props.mpgsAccount.gateway_host,
            merchantId: props.mpgsAccount.merchant_id,
            apiVersion: props.mpgsAccount.api_version,
        })

        ready.value = await prepare()
    } catch (e) {
        console.error('[MPGS] init failed:', e)
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
    }
})

/** Replace the used session after a decline or a failed 3DS so the client can retry. */
async function reprepare(): Promise<void> {
    try {
        await prepare()
    } catch (e) {
        console.error('[MPGS] re-prepare failed:', e)
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
    }
}

async function submit(): Promise<void> {
    if (!ready.value || !session) {
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
        await captureCard()

        const threeDs = await authenticatePayer(session, '#mpgs-3ds-challenge')

        if (!threeDs.authenticated) {
            errorMessage.value = 'Your bank could not verify this payment. Please try a different card.'
            await reprepare()
            processing.value = false

            return
        }

        // NEVER write DB status here — the server classifies the gateway's own
        // synchronous response and writes status; this only reacts to what it
        // reports back. The request carries the attempt id and nothing else:
        // amount, currency and session id all come from the server.
        const response = await fetch(`/pay/${props.payment.uuid}/mpgs/pay`, {
            method: 'POST',
            headers: xsrfHeaders(),
            body: JSON.stringify({ attempt_id: session.attempt_id }),
        })

        const data = await response.json().catch(() => ({}))

        if (response.status === 422) {
            errorMessage.value = data.error ?? 'This payment session is no longer valid. Please try again.'
            await reprepare()
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
            await reprepare()
            processing.value = false

            return
        }

        // 'unknown': the synchronous response didn't resolve cleanly; hand off to
        // the parent page's processing/poll state rather than trying again here.
        emit('unknown')
    } catch (e) {
        console.error('[MPGS] payment failed:', e)
        errorMessage.value = 'An unexpected error occurred. Please try again.'
        processing.value = false
    }
}
</script>

<template>
    <div class="space-y-6">
        <!-- Loading skeleton — shown until the hosted fields mount. -->
        <div v-if="!ready && !errorMessage" class="space-y-3">
            <div class="skeleton-row h-11 rounded-lg"></div>
            <div class="grid grid-cols-3 gap-3">
                <div class="skeleton-row h-11 rounded-lg"></div>
                <div class="skeleton-row h-11 rounded-lg"></div>
                <div class="skeleton-row h-11 rounded-lg"></div>
            </div>
        </div>

        <!-- Fatal error before the card fields could render -->
        <Alert v-if="!ready && errorMessage" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertDescription>{{ errorMessage }}</AlertDescription>
        </Alert>

        <!-- The mpgs-* containers must always exist in the DOM so the gateway can find them. -->
        <form v-show="ready" @submit.prevent="submit" class="space-y-4">
            <div
                class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                :class="fieldInvalid.cardholderName ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
            >
                <div id="mpgs-cardholder-name" class="mpgs-field"></div>
            </div>
            <div
                class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                :class="fieldInvalid.cardNumber ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
            >
                <div id="mpgs-card-number" class="mpgs-field"></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div
                    class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.expiryMonth ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <div id="mpgs-expiry-month" class="mpgs-field"></div>
                </div>
                <div
                    class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.expiryYear ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <div id="mpgs-expiry-year" class="mpgs-field"></div>
                </div>
                <div
                    class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.securityCode ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <div id="mpgs-security-code" class="mpgs-field"></div>
                </div>
            </div>

            <!-- Bank 3DS challenge renders here, inline on the page. -->
            <div id="mpgs-3ds-challenge" class="w-full"></div>

            <Alert v-if="ready && errorMessage" variant="destructive">
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
.mpgs-field-wrap {
    display: flex;
    align-items: center;
    min-height: 44px;
}

.mpgs-field {
    flex: 1;
    min-height: 18px;
}

/* Focus ring on the wrapper when its hosted field frame has focus. */
.mpgs-field-wrap:focus-within {
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
