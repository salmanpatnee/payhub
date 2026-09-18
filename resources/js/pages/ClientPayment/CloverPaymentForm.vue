<script setup lang="ts">
import { ref, onMounted } from 'vue'
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

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let clover: any = null

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
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
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
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const Clover = (window as any).Clover
    // WR-02 analog: null-guard the SDK before use
    if (!loaded || !Clover) {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
        return
    }

    try {
        clover = new Clover(props.cloverAccount.api_access_key, {
            merchantId: props.cloverAccount.merchant_id,
        })
        const elements = clover.elements()

        const styles = {
            input: {
                fontSize: '15px',
                fontFamily: 'inherit',
                color: '#0f172a',
            },
        }

        const cardNumber = elements.create('CARD_NUMBER', styles)
        const cardDate = elements.create('CARD_DATE', styles)
        const cardCvv = elements.create('CARD_CVV', styles)
        const cardPostalCode = elements.create('CARD_POSTAL_CODE', styles)

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
            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden px-3">
                <div id="clv-card-number" class="clv-field"></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border border-slate-200 bg-white overflow-hidden px-3">
                    <div id="clv-card-date" class="clv-field"></div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white overflow-hidden px-3">
                    <div id="clv-card-cvv" class="clv-field"></div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white overflow-hidden px-3">
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
.clv-field {
    height: 44px;
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
