<script setup lang="ts">
import { AlertCircle, LockIcon } from 'lucide-vue-next'
import { onMounted, ref } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { loadAcceptJs, tokenizeCard } from '@/lib/acceptjs'
import {
    formatCardNumber,
    formatExpiry,
    parseExpiry,
    validateCard
    
} from '@/lib/card'
import type {CardFieldErrors} from '@/lib/card';

type Outcome = 'approved' | 'held' | 'declined' | 'check_details' | 'unclear' | 'error'

const props = defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
    }
    authorizenetAccount: {
        api_login_id: string
        public_client_key: string
        script_url: string
    }
    // Records consent for the audit trail; resolves false (and the parent shows why)
    // if consent is missing or couldn't be recorded. Called before any card data is sent.
    beforeCharge: () => Promise<boolean>
}>()

const emit = defineEmits<{
    outcome: [outcome: 'approved' | 'held' | 'unclear']
}>()

// Card values live only in these refs. They are never put in Inertia form state, a URL,
// localStorage, a log or the console (spec 0007, AC-4).
const number = ref('')
const expiry = ref('')
const cvv = ref('')
const zip = ref('')

const ready = ref(false)
const processing = ref(false)
const fieldErrors = ref<CardFieldErrors>({})
const message = ref<string | null>(null)

const FIXED = {
    declined: 'Your card was declined. Please try a different card.',
    check_details: 'Please check your card details and try again.',
    error: 'Your payment could not be processed. Please try again or use a different card.',
} as const

// Drop a field's own error as soon as the customer edits it, so a stale message never sits under new input.
function clearError(field: keyof CardFieldErrors): void {
    if (fieldErrors.value[field]) {
        fieldErrors.value = { ...fieldErrors.value, [field]: undefined }
    }
}

function wrapClass(error?: string): string {
    return error ? 'border-red-300 ring-2 ring-red-100' : 'border-slate-200 hover:border-slate-300'
}

function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency.toUpperCase() }).format(cents / 100)
}

function csrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    }
}

onMounted(async () => {
    try {
        await loadAcceptJs(props.authorizenetAccount.script_url)
        ready.value = true
    } catch {
        message.value = FIXED.error
    }
})

async function submit(): Promise<void> {
    if (processing.value || !ready.value) {
        return
    }

    message.value = null
    fieldErrors.value = validateCard({ number: number.value, expiry: expiry.value, cvv: cvv.value, zip: zip.value })

    if (Object.keys(fieldErrors.value).length > 0) {
        return
    }

    processing.value = true

    try {
        if (!(await props.beforeCharge())) {
            return
        }

        const parsed = parseExpiry(expiry.value)!
        const opaque = await tokenizeCard(
            { apiLoginId: props.authorizenetAccount.api_login_id, clientKey: props.authorizenetAccount.public_client_key },
            { number: number.value.replace(/\D/g, ''), month: parsed.month, year: parsed.year, cvv: cvv.value, zip: zip.value },
        )

        // The card number and security code are no longer needed once tokenizing returns.
        number.value = ''
        cvv.value = ''

        if (!opaque) {
            message.value = FIXED.check_details

            return
        }

        const response = await fetch(`/pay/${props.payment.uuid}/authorizenet/charge`, {
            method: 'POST',
            headers: csrfHeaders(),
            body: JSON.stringify({
                data_descriptor: opaque.dataDescriptor,
                data_value: opaque.dataValue,
                postal_code: zip.value,
            }),
        })

        const data: { outcome?: Outcome } = await response.json().catch(() => ({}))

        // Too many failed cards: the server paused this payment. Reload so the page shows the paused notice.
        if (response.status === 423) {
            window.location.reload()

            return
        }

        if (response.status === 429) {
            message.value = 'Too many attempts. Please try again later.'

            return
        }

        if (response.status === 422 && !data.outcome) {
            message.value = FIXED.error

            return
        }

        switch (data.outcome) {
            case 'approved':
            case 'held':
            case 'unclear':
                emit('outcome', data.outcome)

                break
            case 'declined':
            case 'check_details':
                message.value = FIXED[data.outcome]

                break
            default:
                message.value = FIXED.error
        }
    } catch {
        // The charge request may have reached the server: treat a lost response as unclear
        // rather than inviting a second payment.
        emit('outcome', 'unclear')
    } finally {
        processing.value = false
    }
}
</script>

<template>
    <div class="space-y-6">
        <div v-if="!ready && !message" class="space-y-4" role="status" aria-label="Loading card fields">
            <div class="flex h-[44px] items-center rounded-xl border border-slate-200 bg-white px-3">
                <div class="an-skeleton-bar h-2.5 rounded-full" style="width: 38%"></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div v-for="n in 3" :key="n" class="flex h-[44px] items-center rounded-xl border border-slate-200 bg-white px-3">
                    <div class="an-skeleton-bar h-2.5 rounded-full" style="width: 50%"></div>
                </div>
            </div>
        </div>

        <Alert v-if="!ready && message" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertDescription>{{ message }}</AlertDescription>
        </Alert>

        <!-- Not a <form>: the card inputs can never submit natively or end up in a URL.
             sentry-block keeps Sentry session replay from recording the card values. -->
        <div v-show="ready" class="sentry-block space-y-4" @keydown.enter.prevent="submit">
            <div>
                <div class="an-field-wrap rounded-xl border bg-white px-3 transition-colors duration-150" :class="wrapClass(fieldErrors.number)">
                    <input
                        id="an-card-number"
                        :value="number"
                        class="an-field"
                        type="text"
                        inputmode="numeric"
                        autocomplete="cc-number"
                        placeholder="Card number"
                        aria-label="Card number"
                        maxlength="23"
                        :aria-invalid="!!fieldErrors.number"
                        @input="number = formatCardNumber(($event.target as HTMLInputElement).value); clearError('number')"
                    >
                </div>
                <p v-if="fieldErrors.number" class="mt-1.5 text-xs text-red-600">{{ fieldErrors.number }}</p>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <div class="an-field-wrap rounded-xl border bg-white px-3 transition-colors duration-150" :class="wrapClass(fieldErrors.expiry)">
                        <input
                            id="an-card-expiry"
                            :value="expiry"
                            class="an-field"
                            type="text"
                            inputmode="numeric"
                            autocomplete="cc-exp"
                            placeholder="MM/YY"
                            aria-label="Expiry date"
                            maxlength="5"
                            :aria-invalid="!!fieldErrors.expiry"
                            @input="expiry = formatExpiry(($event.target as HTMLInputElement).value); clearError('expiry')"
                        >
                    </div>
                    <p v-if="fieldErrors.expiry" class="mt-1.5 text-xs text-red-600">{{ fieldErrors.expiry }}</p>
                </div>
                <div>
                    <div class="an-field-wrap rounded-xl border bg-white px-3 transition-colors duration-150" :class="wrapClass(fieldErrors.cvv)">
                        <input
                            id="an-card-cvv"
                            v-model="cvv"
                            class="an-field"
                            type="password"
                            inputmode="numeric"
                            autocomplete="cc-csc"
                            placeholder="CVV"
                            aria-label="Security code"
                            maxlength="4"
                            :aria-invalid="!!fieldErrors.cvv"
                            @input="clearError('cvv')"
                        >
                    </div>
                    <p v-if="fieldErrors.cvv" class="mt-1.5 text-xs text-red-600">{{ fieldErrors.cvv }}</p>
                </div>
                <div>
                    <div class="an-field-wrap rounded-xl border bg-white px-3 transition-colors duration-150" :class="wrapClass(fieldErrors.zip)">
                        <input
                            id="an-card-zip"
                            v-model="zip"
                            class="an-field"
                            type="text"
                            autocomplete="postal-code"
                            placeholder="ZIP"
                            aria-label="ZIP code"
                            maxlength="10"
                            :aria-invalid="!!fieldErrors.zip"
                            @input="clearError('zip')"
                        >
                    </div>
                    <p v-if="fieldErrors.zip" class="mt-1.5 text-xs text-red-600">{{ fieldErrors.zip }}</p>
                </div>
            </div>

            <Alert v-if="ready && message" variant="destructive">
                <AlertCircle class="size-4" />
                <AlertDescription>{{ message }}</AlertDescription>
            </Alert>

            <Button
                type="button"
                size="lg"
                class="w-full cursor-pointer bg-[var(--brand-primary)] font-semibold tracking-wide text-white hover:bg-[var(--brand-primary)]/90 focus-visible:ring-[var(--brand-primary)]/50"
                :disabled="processing"
                @click="submit"
            >
                <Spinner v-if="processing" class="mr-2 size-4" />
                <span>{{ processing ? 'Processing…' : `Pay ${formatAmount(payment.amount, payment.currency)}` }}</span>
            </Button>

            <p class="flex items-center justify-center gap-1.5 text-center text-xs leading-relaxed text-slate-600">
                <LockIcon class="size-3 shrink-0" />
                Your card details are never stored · 256-bit SSL
            </p>
        </div>
    </div>
</template>

<style scoped>
.an-field-wrap {
    display: flex;
    align-items: center;
    min-height: 44px;
}

.an-field {
    display: block;
    width: 100%;
    height: 42px;
    border: 0;
    background: transparent;
    outline: none;
    font-size: 15px;
    color: #0f172a;
}

.an-field::placeholder {
    color: #94a3b8;
}

/* Same brand focus ring as the MPGS and Clover card fields. */
.an-field-wrap:focus-within {
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-primary) 15%, transparent);
}

.an-skeleton-bar {
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: an-shimmer 1.4s ease-in-out infinite;
}

@keyframes an-shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>
