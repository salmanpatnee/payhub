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
import { reloadWithNotice, takeNotice } from '@/lib/mpgs/notice'

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
    cardNumber: '#card-number',
    expiryMonth: '#expiry-month',
    expiryYear: '#expiry-year',
    securityCode: '#security-code',
    cardholderName: '#cardholder-name',
}

// The server issued session for this page load. The gateway libraries configure
// once per page load, so a retry after a decline reloads the page (new session,
// new order id) rather than re-mounting.
let session: MpgsSession | null = null

function formatAmount(cents: number, currency: string): string {
    const formatter = new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : currency === 'pkr' ? 'en-PK' : 'en-US',
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
 * Ask the server for a session (it writes amount, currency and the 3DS fields
 * from the Payment record onto it) and attach the hosted fields to it. Returns
 * false and sets errorMessage when the processor could not be reached.
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
    const notice = takeNotice(props.payment.uuid)

    try {
        await loadLibraries({
            gatewayHost: props.mpgsAccount.gateway_host,
            merchantId: props.mpgsAccount.merchant_id,
            apiVersion: props.mpgsAccount.api_version,
        })

        ready.value = await prepare()

        if (ready.value && notice) {
            errorMessage.value = notice
        }
    } catch (e) {
        console.error('[MPGS] init failed:', e)
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
    }
})

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

        const threeDs = await authenticatePayer(session)

        if (!threeDs.authenticated) {
            reloadWithNotice(props.payment.uuid, 'Your bank could not verify this payment. Please try a different card.')

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
            reloadWithNotice(props.payment.uuid, data.error ?? 'This payment session is no longer valid. Please try again.')

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
            reloadWithNotice(props.payment.uuid, data.message ?? 'Your card was declined. Please try a different card.')

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
        <!-- Fatal error before the card fields could render -->
        <Alert v-if="!ready && errorMessage" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertDescription>{{ errorMessage }}</AlertDescription>
        </Alert>

        <!-- The card inputs must always exist in the DOM so the gateway can find them. They are
             readonly with no name attribute: the gateway replaces them with its own frames. -->
        <!-- Never hide this form (no v-show / display:none / zero height): the gateway sizes each hosted
             frame from its input when it mounts and only finishes initializing for rendered frames.
             The Pay button stays disabled until the fields are ready instead. -->
        <form @submit.prevent="submit" class="space-y-4">
            <div
                class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                :class="fieldInvalid.cardholderName ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
            >
                <input id="cardholder-name" type="text" class="mpgs-field" placeholder="Name on card" title="cardholder name" aria-label="enter name on card" tabindex="1" readonly>
            </div>
            <div
                class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                :class="fieldInvalid.cardNumber ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
            >
                <input id="card-number" type="text" class="mpgs-field" placeholder="Card number" title="card number" aria-label="enter your card number" tabindex="2" readonly>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div
                    class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.expiryMonth ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <input id="expiry-month" type="text" class="mpgs-field" placeholder="MM" title="expiry month" aria-label="two digit expiry month" tabindex="3" readonly>
                </div>
                <div
                    class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.expiryYear ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <input id="expiry-year" type="text" class="mpgs-field" placeholder="YY" title="expiry year" aria-label="two digit expiry year" tabindex="4" readonly>
                </div>
                <div
                    class="mpgs-field-wrap rounded-xl border bg-white overflow-hidden px-3 transition-colors duration-150"
                    :class="fieldInvalid.securityCode ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'"
                >
                    <input id="security-code" type="text" class="mpgs-field" placeholder="CVV" title="security code" aria-label="three digit security code" tabindex="5" readonly>
                </div>
            </div>

            <Alert v-if="ready && errorMessage" variant="destructive">
                <AlertCircle class="size-4" />
                <AlertDescription>{{ errorMessage }}</AlertDescription>
            </Alert>

            <Button
                type="submit"
                size="lg"
                class="w-full bg-[var(--brand-primary)] text-white hover:bg-[var(--brand-primary)]/90 focus-visible:ring-[var(--brand-primary)]/50 font-semibold tracking-wide cursor-pointer"
                :disabled="processing || !ready"
            >
                <Spinner v-if="processing" class="size-4 mr-2" />
                <span>{{ processing ? 'Processing…' : `Pay ${formatAmount(payment.amount, payment.currency)}` }}</span>
            </Button>

            <p class="flex items-center justify-center gap-1.5 text-xs text-slate-600 text-center leading-relaxed">
                <LockIcon class="size-3 shrink-0" />
                Your card details are never stored · 256-bit SSL
            </p>
        </form>

        <!-- Bank 3DS challenge renders here, inline on the page. It must stay OUTSIDE the payment
             <form>: the gateway's challenge markup contains its own <form>, and browsers drop a form
             nested inside another one when the HTML is inserted, leaving nothing to submit. -->
        <div id="mpgs-3ds-challenge" class="w-full"></div>
    </div>
</template>

<style scoped>
/* Keep this box a plain block, not flex. The gateway measures the readonly input while its
   frame still sits beside it, then copies that width and every computed style into the
   field inside the frame. In a flex row the frame squeezes the input to 0px, so the real
   field inside the frame ends up 0px wide and cannot be typed into. */
.mpgs-field {
    display: block;
    width: 100%;
    height: 42px;
    border: 0;
    background: transparent;
    outline: none;
}

.mpgs-field-wrap :deep(iframe) {
    display: block;
}

/* Focus ring on the wrapper when its hosted field frame has focus. */
.mpgs-field-wrap:focus-within {
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-primary) 15%, transparent);
}
</style>
