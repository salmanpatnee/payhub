<script setup lang="ts">
import { mountNmiPayments, mountNmiThreeDSecure } from '@nmipayments/nmi-pay'
import type { PaymentEvent, ThreeDSecureCompleteEvent } from '@nmipayments/nmi-pay'
import { AlertCircle } from 'lucide-vue-next'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

/**
 * NMI Payment Component (spec 0012, AC-5, AC-6, AC-12). The card (and Google Pay) values live inside NMI's
 * frames. This page only ever holds the one time token and the 3DS result in local variables: never in Inertia
 * form state, a URL, storage, a log or the console. The wrapper carries `sentry-block`. The amount and currency
 * come from props built on the server and are used for display and for 3DS only; the server never reads them back.
 */
const props = defineProps<{
    payment: { uuid: string; amount: number; currency: string }
    nmiAccount: { tokenization_key: string; three_ds_enabled: boolean; google_pay_enabled: boolean }
    clientName: string
    consentGiven: boolean
    beforeCharge: () => Promise<boolean>
}>()

const emit = defineEmits<{ outcome: [outcome: 'approved' | 'unclear'] }>()

type Outcome = 'approved' | 'declined' | 'check_details' | 'unclear' | 'error' | '3ds_failed'
type Method = 'card' | 'google_pay'

const FIXED: Record<string, string> = {
    declined: 'Your card was declined. Please try a different card.',
    check_details: 'Please check your card details and try again.',
    error: 'Your payment could not be processed. Please try again or use a different card.',
    '3ds_failed': 'We could not verify your card. Please try again or use a different card.',
    name: 'Please enter the name on your card.',
    rate: 'Too many attempts. Please wait a while, or contact your representative for help.',
}

const name = ref(props.clientName)
const nameError = ref<string | undefined>(undefined)
const message = ref<string | null>(null)
const ready = ref(false)

const paymentEl = ref<HTMLElement | null>(null)
const threeDsEl = ref<HTMLElement | null>(null)
let widget: ReturnType<typeof mountNmiPayments> | null = null
let threeDs: ReturnType<typeof mountNmiThreeDSecure> | null = null
// Settles the Payment Component's pay button once the charge (or the 3DS step) is over.
let settlePay: ((result: true | string) => void) | null = null
let pendingToken = ''
let pendingName: [string, string] = ['', '']

function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency.toUpperCase() }).format(cents / 100)
}

// Integer math, display and 3DS only (the server charges from its own Payment record).
function decimalAmount(cents: number): string {
    return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`
}

function csrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    }
}

/** Split on the last space; a single word is used for both names. */
function splitName(full: string): [string, string] {
    const trimmed = full.trim()
    const at = trimmed.lastIndexOf(' ')

    return at === -1 ? [trimmed, trimmed] : [trimmed.slice(0, at).trim(), trimmed.slice(at + 1).trim()]
}

function resetForm(): void {
    // Tokens are single use and a 3DS result is tied to its token: the next try starts clean.
    pendingToken = ''
    widget?.resetFields()
}

/**
 * Sends the token (and the 3DS result for a card) to PayHub. Returns the text to show, or true when the
 * payment is approved or is being confirmed (the page then leaves the form).
 */
async function charge(token: string, method: Method, first: string, last: string, auth?: ThreeDSecureCompleteEvent): Promise<true | string> {
    try {
        const response = await fetch(`/pay/${props.payment.uuid}/nmi/charge`, {
            method: 'POST',
            headers: csrfHeaders(),
            body: JSON.stringify({
                payment_token: token,
                method,
                first_name: first,
                last_name: last,
                ...(auth
                    ? {
                          // The component's types say string, but the sandbox sends eci as a number (5), which the
                          // server rule rejects. Send every 3DS value as text.
                          cavv: String(auth.cavv ?? ''),
                          eci: String(auth.eci ?? ''),
                          xid: auth.xid == null ? undefined : String(auth.xid),
                          card_holder_auth: String(auth.cardHolderAuth ?? ''),
                          three_ds_version: String(auth.threeDsVersion ?? ''),
                          directory_server_id: String(auth.directoryServerId ?? ''),
                      }
                    : {}),
            }),
        })
        const data: { outcome?: Outcome } = await response.json().catch(() => ({}))

        // Too many failed cards: the server paused this payment. Reload so the page shows the paused notice.
        if (response.status === 423) {
            window.location.reload()

            return true
        }

        if (response.status === 429) {
            resetForm()

            return FIXED.rate
        }

        // A 409 means a charge is still being confirmed: treat it exactly like an unclear outcome.
        if (data.outcome === 'approved') {
            emit('outcome', 'approved')

            return true
        }

        if (data.outcome === 'unclear' || response.status === 409) {
            emit('outcome', 'unclear')

            return true
        }

        resetForm()

        return FIXED[data.outcome ?? 'error'] ?? FIXED.error
    } catch {
        // The request may have reached the server: treat a lost response as unclear, never invite a second payment.
        emit('outcome', 'unclear')

        return true
    }
}

async function reportThreeDsFailure(method: Method, first: string, last: string): Promise<void> {
    try {
        await fetch(`/pay/${props.payment.uuid}/nmi/charge`, {
            method: 'POST',
            headers: csrfHeaders(),
            body: JSON.stringify({ three_ds_failed: true, method, first_name: first, last_name: last }),
        })
    } catch {
        // Diagnostic only: the customer still sees the fixed failure message.
    }
}

/** The card path: consent, name, then 3DS (when on) before the server charge. */
async function onPay(event: PaymentEvent): Promise<true | string> {
    message.value = null
    nameError.value = undefined

    if (name.value.trim() === '') {
        nameError.value = FIXED.name

        return FIXED.name
    }

    if (!(await props.beforeCharge())) {
        return 'Please agree to the policies below before paying.'
    }

    const [first, last] = splitName(name.value)

    if (!props.nmiAccount.three_ds_enabled) {
        return charge(event.token, 'card', first, last)
    }

    pendingToken = event.token
    pendingName = [first, last]

    return new Promise<true | string>((resolve) => {
        settlePay = resolve
        threeDs?.startThreeDSecure({
            paymentToken: event.token,
            currency: props.payment.currency.toUpperCase(),
            amount: decimalAmount(props.payment.amount),
            firstName: first,
            lastName: last,
        })
    })
}

async function onThreeDsComplete(result: ThreeDSecureCompleteEvent): Promise<void> {
    const outcome = await charge(pendingToken, 'card', pendingName[0], pendingName[1], result)

    settlePay?.(outcome)
    settlePay = null
}

async function onThreeDsFailure(): Promise<void> {
    // No charge is sent when 3DS fails or is cancelled; only a report so the attempt shows to admins.
    await reportThreeDsFailure('card', pendingName[0], pendingName[1])
    resetForm()
    settlePay?.(FIXED['3ds_failed'])
    settlePay = null
}

/** Google Pay: the wallet token is charged without 3DS (AC-6). Consent is recorded first. */
async function onExpressCheckout(event: PaymentEvent): Promise<void> {
    message.value = null

    if (!(await props.beforeCharge())) {
        message.value = 'Please agree to the policies below before paying.'
        resetForm()

        return
    }

    const [first, last] = splitName(name.value || props.clientName || 'Customer')
    const result = await charge(event.token, 'google_pay', first, last)

    if (result !== true) {
        message.value = result
    }
}

onMounted(() => {
    if (!paymentEl.value) {
        return
    }

    try {
        const wallets = props.nmiAccount.google_pay_enabled
        const country = props.payment.currency.toLowerCase() === 'gbp' ? 'GB' : 'US'

        widget = mountNmiPayments(paymentEl.value, {
            tokenizationKey: props.nmiAccount.tokenization_key,
            layout: 'multiLine',
            paymentMethods: wallets ? ['card', 'google-pay'] : ['card'],
            payButtonText: `Pay ${formatAmount(props.payment.amount, props.payment.currency)}`,
            onFieldsAvailable: () => {
                ready.value = true
            },
            onPay,
            ...(wallets
                ? {
                      expressCheckoutConfig: {
                          amount: decimalAmount(props.payment.amount),
                          currency: props.payment.currency.toUpperCase(),
                          countryCode: country,
                          googlePay: {},
                      },
                      onExpressCheckout,
                  }
                : {}),
        })

        if (props.nmiAccount.three_ds_enabled && threeDsEl.value) {
            threeDs = mountNmiThreeDSecure(threeDsEl.value, {
                tokenizationKey: props.nmiAccount.tokenization_key,
                modal: true,
                onComplete: onThreeDsComplete,
                onFailure: onThreeDsFailure,
            })
        }
    } catch {
        message.value = FIXED.error
    }
})

onBeforeUnmount(() => {
    widget?.destroy()
    threeDs?.destroy()
})
</script>

<template>
    <div class="space-y-5">
        <div class="space-y-1.5">
            <Label for="nmi-name" class="text-sm font-medium text-slate-700">Name on card</Label>
            <Input
                id="nmi-name"
                v-model="name"
                type="text"
                autocomplete="cc-name"
                maxlength="100"
                class="sentry-block h-11 rounded-xl"
                :aria-invalid="!!nameError"
                @input="nameError = undefined"
            />
            <p v-if="nameError" class="flex items-start gap-1.5 text-[12px] font-medium text-red-600">
                <AlertCircle class="mt-px size-3.5 shrink-0" />
                <span>{{ nameError }}</span>
            </p>
        </div>

        <!-- NMI frames hold the card values. The wrapper is blocked from Sentry session replay. -->
        <div v-if="!ready && !message" class="flex h-[44px] items-center rounded-xl border border-slate-200 bg-white px-3" role="status" aria-label="Loading card fields">
            <div class="h-2.5 w-2/5 animate-pulse rounded-full bg-slate-200"></div>
        </div>
        <div ref="paymentEl" class="sentry-block" :class="consentGiven ? '' : 'opacity-60'"></div>
        <div ref="threeDsEl" class="sentry-block"></div>

        <Alert v-if="message" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertDescription>{{ message }}</AlertDescription>
        </Alert>
    </div>
</template>
