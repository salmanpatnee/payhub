<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { AlertCircle, LockIcon } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Spinner } from '@/components/ui/spinner'

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
}>()

const sdkLoaded    = ref(false)
const processing   = ref(false)
const errorMessage = ref<string | null>(null)
const cardFocused  = ref(false)
const cardInvalid  = ref(false)

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let card: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let payments: any = null

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

// Load the environment-correct Web Payments SDK. Resolves false if the CDN fails (WR-01 analog).
function loadSquareSdk(environment: string): Promise<boolean> {
    return new Promise((resolve) => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
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
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
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
        card.addEventListener('focusClassAdded', () => { cardFocused.value = true })
        card.addEventListener('focusClassRemoved', () => { cardFocused.value = false })
        card.addEventListener('errorClassAdded', () => { cardInvalid.value = true })
        card.addEventListener('errorClassRemoved', () => { cardInvalid.value = false })
        sdkLoaded.value = true
    } catch (e) {
        console.error('[Square] init failed:', e)
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
    }
})

onBeforeUnmount(() => {
    if (card) {
        try { card.destroy() } catch { /* noop */ }
    }
})

/**
 * SCA verification (3DS / Strong Customer Authentication, required in the UK).
 * verifyBuyer is deprecated by Square but remains the current SCA path — isolated here
 * for an easy future swap. Returns the verification token, or null if unavailable.
 */
async function verifyBuyer(sourceId: string): Promise<string | null> {
    if (!payments) return null
    try {
        const result = await payments.verifyBuyer(sourceId, {
            amount: (props.payment.amount / 100).toFixed(2),
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

        // NEVER write DB status here — authoritative status comes from the webhook only.
        const response = await fetch(`/pay/${props.payment.uuid}/square`, {
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
            window.location.href = `/pay/${props.payment.uuid}/success?redirect_status=succeeded`
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

        <!-- #sq-card must always exist in the DOM so card.attach() can find it on mount.
             The wrapper draws the visible border/focus/error state (Square's own is
             transparent), and crops the reserved-but-unused message row Square renders
             beneath the input line — the app already surfaces errors via the Alert below. -->
        <form v-show="sdkLoaded" @submit.prevent="submit" class="space-y-5">
            <div
                class="rounded-xl border bg-white transition-colors duration-150"
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
