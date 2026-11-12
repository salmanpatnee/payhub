<script setup lang="ts">
import { AlertCircle, LockIcon } from 'lucide-vue-next'
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { withBase } from '@/lib/basePath'

const props = defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
    }
    brand: {
        name: string
        primary_color: string
    }
    squareAccount: {
        application_id: string
        location_id: string
        environment: 'sandbox' | 'production'
    }
    referenceCode: string | null
    // Owned by Pay.vue: true while the consent box is ticked.
    consentGiven: boolean
    // Owned by Pay.vue: checks the consent box and records consent on the server.
    // Resolves false (and Pay.vue shows why) when it could not.
    recordConsent: () => Promise<boolean>
}>()

const sdkLoaded    = ref(false)
const processing   = ref(false)
const errorMessage = ref<string | null>(null)
const cardFocused  = ref(false)
const cardInvalid  = ref(false)

 
let card: any = null
 
let payments: any = null
// Wallet instances. Each one is optional: a wallet that cannot start (unsupported
// browser, domain not registered for Apple Pay, non US account for Cash App Pay)
// simply stays hidden and the card form still works.
 
let applePay: any = null
 
let googlePay: any = null
 
let cashAppPay: any = null

const showApplePay  = ref(false)
const showGooglePay = ref(false)
const showCashApp   = ref(false)
const hasWallet     = computed(() => showApplePay.value || showGooglePay.value || showCashApp.value)

const WALLET_FAILED = 'Your payment could not be completed. Please try again or pay by card.'

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

// Cents to Square's decimal string with integer math only (never a float).
function centsToDecimal(cents: number): string {
    return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`
}

// Load the environment-correct Web Payments SDK. Resolves false if the CDN fails (WR-01 analog).
function loadSquareSdk(environment: string): Promise<boolean> {
    return new Promise((resolve) => {
         
        if ((window as any).Square) {
            resolve(true)

            return
        }

        const src = environment === 'production'
            ? 'https://web.squarecdn.com/v1/square.js'
            : 'https://sandbox.web.squarecdn.com/v1/square.js'
        const script = document.createElement('script')
        script.src = src
        script.onload = () => resolve(true)
        script.onerror = () => resolve(false)
        document.head.appendChild(script)
    })
}

onMounted(async () => {
    const loaded = await loadSquareSdk(props.squareAccount.environment)
     
    const Square = (window as any).Square

    // WR-02 analog: null-guard the SDK before use
    if (!loaded || !Square) {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'

        return
    }

    try {
        payments = Square.payments(props.squareAccount.application_id, props.squareAccount.location_id)
        card = await payments.card({
            style: {
                input: {
                    fontSize: '15px',
                    fontFamily: 'inherit',
                    color: '#0f172a',
                },
                'input::placeholder': { color: '#94a3b8' },
                // Border/focus/error are drawn by our own wrapper (below) so the
                // field's reserved message row can be cropped without losing the box outline.
                '.input-container': { borderColor: 'transparent' },
                '.input-container.is-focus': { borderColor: 'transparent' },
                '.input-container.is-error': { borderColor: 'transparent' },
                '.message-text': { color: '#64748b' },
                '.message-text.is-error': { color: '#dc2626' },
            },
        })
        await card.attach('#sq-card')
        // Mirror Square's internal focus/validity state onto our own wrapper so the
        // outline still reacts, even though Square's own border is now transparent.
        card.addEventListener('focusClassAdded', () => {
 cardFocused.value = true 
})
        card.addEventListener('focusClassRemoved', () => {
 cardFocused.value = false 
})
        card.addEventListener('errorClassAdded', () => {
 cardInvalid.value = true 
})
        card.addEventListener('errorClassRemoved', () => {
 cardInvalid.value = false 
})
        sdkLoaded.value = true
    } catch (e) {
        console.error('[Square] init failed:', e)
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'

        return
    }

    await initWallets()
})

onBeforeUnmount(() => {
    for (const instance of [card, googlePay, applePay, cashAppPay]) {
        if (instance) {
            try {
 instance.destroy() 
} catch { /* noop */ }
        }
    }
})

 
function buildPaymentRequest(): any {
    return payments.paymentRequest({
        // A Square account is single currency, so the currency tells us the account's country.
        countryCode: props.payment.currency === 'gbp' ? 'GB' : 'US',
        currencyCode: props.payment.currency.toUpperCase(),
        total: { amount: centsToDecimal(props.payment.amount), label: props.brand.name },
    })
}

async function initWallets(): Promise<void> {
    try {
        googlePay = await payments.googlePay(buildPaymentRequest())
        await googlePay.attach('#sq-google-pay', { buttonColor: 'black', buttonSizeMode: 'fill', buttonType: 'long' })
        showGooglePay.value = true
    } catch {
        googlePay = null
    }

    try {
        // Rejects unless the browser supports Apple Pay and this domain is registered with Square.
        applePay = await payments.applePay(buildPaymentRequest())
        showApplePay.value = true
    } catch {
        applePay = null
    }

    // Cash App Pay is US only, so only USD payments try it.
    if (props.payment.currency === 'usd') {
        try {
            cashAppPay = await payments.cashAppPay(buildPaymentRequest(), {
                // On a phone the client leaves for Cash App and comes back to this page.
                redirectURL: window.location.href,
                referenceId: props.referenceCode ?? props.payment.uuid,
            })
             
            cashAppPay.addEventListener('ontokenization', (event: any) => {
                const result = event?.detail?.tokenResult

                if (result?.status === 'OK') {
                    void payWithToken(result.token, null)
                } else if (result && result.status !== 'Cancel') {
                    errorMessage.value = WALLET_FAILED
                }
            })
            await cashAppPay.attach('#sq-cash-app-pay', { shape: 'semiround', width: 'full' })
            showCashApp.value = true
        } catch {
            cashAppPay = null
        }
    }
}

// Apple Pay and Google Pay must open their sheet straight from the click, so tokenize
// comes first; consent is still recorded before the charge (in payWithToken).
 
async function payWithWallet(wallet: any): Promise<void> {
    if (!wallet || processing.value) {
return
}

    if (!props.consentGiven) {
        // Shows the consent error in Pay.vue; no sheet opens.
        await props.recordConsent()

        return
    }

    errorMessage.value = null

    try {
        const result = await wallet.tokenize()

        if (result?.status === 'OK') {
            await payWithToken(result.token, null)
        } else if (result?.status !== 'Cancel') {
            errorMessage.value = WALLET_FAILED
        }
    } catch {
        errorMessage.value = WALLET_FAILED
    }
}

function onGooglePayClick(): void {
    void payWithWallet(googlePay)
}

function onApplePayClick(): void {
    void payWithWallet(applePay)
}

/**
 * SCA verification (3DS / Strong Customer Authentication, required in the UK).
 * verifyBuyer is deprecated by Square but remains the current SCA path — isolated here
 * for an easy future swap. Returns the verification token, or null if unavailable.
 */
async function verifyBuyer(sourceId: string): Promise<string | null> {
    if (!payments) {
return null
}

    try {
        const result = await payments.verifyBuyer(sourceId, {
            amount: centsToDecimal(props.payment.amount),
            currencyCode: props.payment.currency.toUpperCase(),
            intent: 'CHARGE',
            billingContact: {},
        })

        return result?.token ?? null
    } catch {
        return null
    }
}

async function submit(): Promise<void> {
    if (!card) {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'

        return
    }

    if (!props.consentGiven) {
        await props.recordConsent()

        return
    }

    processing.value = true
    errorMessage.value = null

    try {
        const tokenResult = await card.tokenize()

        if (tokenResult.status !== 'OK') {
            errorMessage.value = tokenResult.errors?.[0]?.message
                ?? 'Please check your card details and try again.'
            processing.value = false

            return
        }

        const sourceId = tokenResult.token
        const verificationToken = await verifyBuyer(sourceId)
        await payWithToken(sourceId, verificationToken)
    } catch {
        errorMessage.value = 'An unexpected error occurred. Please try again.'
        processing.value = false
    }
}

/**
 * Shared by the card and every wallet: record consent, then charge the token.
 * Wallets pass no verification token: Apple Pay, Google Pay and Cash App Pay do their own check.
 * NEVER write DB status here: authoritative status comes from the webhook only.
 */
async function payWithToken(sourceId: string, verificationToken: string | null): Promise<void> {
    processing.value = true
    errorMessage.value = null

    try {
        if (!(await props.recordConsent())) {
            processing.value = false

            return
        }

        const response = await fetch(withBase(`/pay/${props.payment.uuid}/square`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                source_id: sourceId,
                verification_token: verificationToken,
            }),
        })

        const data = await response.json().catch(() => ({ ok: false }))

        if (response.ok && data.ok) {
            window.location.href = withBase(`/pay/${props.payment.uuid}/success?redirect_status=succeeded`)

            return
        }

        errorMessage.value = data.error ?? 'Your payment could not be processed. Please try again.'
        processing.value = false
    } catch {
        errorMessage.value = 'An unexpected error occurred. Please try again.'
        processing.value = false
    }
}
</script>

<template>
    <div class="space-y-6">
        <!-- Loading skeleton — shown until the SDK attaches the card field.
             Sized to match the compact single-row field so there's no layout shift. -->
        <div v-if="!sdkLoaded && !errorMessage" class="space-y-5">
            <div class="skeleton-row h-[52px] rounded-xl border border-slate-200"></div>
            <div class="skeleton-row h-11 rounded-lg opacity-75"></div>
        </div>

        <!-- Fatal error before the card field could render -->
        <Alert v-if="!sdkLoaded && errorMessage" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertDescription>{{ errorMessage }}</AlertDescription>
        </Alert>

        <!-- One tap wallets. The Google Pay and Cash App containers must always exist so the
             SDK can attach to them; they stay empty (no height) until that wallet starts.
             Faded while the consent box is unticked: a click then only shows the consent error. -->
        <div v-show="sdkLoaded" class="space-y-3" :class="{ 'opacity-50': hasWallet && !consentGiven }">
            <button
                v-if="showApplePay"
                type="button"
                class="apple-pay-button"
                aria-label="Pay with Apple Pay"
                :disabled="processing"
                @click="onApplePayClick"
            ></button>
            <div id="sq-google-pay" :class="{ 'pointer-events-none': processing }" @click="onGooglePayClick"></div>
            <div id="sq-cash-app-pay" :class="{ 'pointer-events-none': processing || !consentGiven }"></div>

            <div v-if="hasWallet" class="flex items-center gap-3 pt-1 text-xs text-slate-400">
                <span class="h-px flex-1 bg-slate-200"></span>
                <span>or pay with card</span>
                <span class="h-px flex-1 bg-slate-200"></span>
            </div>
        </div>

        <!-- #sq-card must always exist in the DOM so card.attach() can find it on mount.
             The wrapper draws the visible border/focus/error state (Square's own is
             transparent), and crops the reserved-but-unused message row Square renders
             beneath the input line — the app already surfaces errors via the Alert below. -->
        <form v-show="sdkLoaded" @submit.prevent="submit" class="space-y-5">
            <div
                class="rounded-xl border bg-white overflow-hidden transition-colors duration-150"
                :class="cardInvalid || errorMessage
                    ? 'border-red-300 ring-2 ring-red-100'
                    : cardFocused
                        ? 'border-[var(--brand-primary)] ring-2 ring-[var(--brand-primary)]/15'
                        : 'border-slate-200 hover:border-slate-300'"
            >
                <div id="sq-card" class="sq-card-field"></div>
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
.sq-card-field {
    height: 52px;
    overflow: hidden;
}

.apple-pay-button {
    display: block;
    width: 100%;
    height: 44px;
    border-radius: 6px;
    cursor: pointer;
    -webkit-appearance: -apple-pay-button;
    -apple-pay-button-type: plain;
    -apple-pay-button-style: black;
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
