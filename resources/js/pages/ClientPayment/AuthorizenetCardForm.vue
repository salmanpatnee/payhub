<script setup lang="ts">
import { AlertCircle, LockIcon } from 'lucide-vue-next'
import { onMounted, ref } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
        <div v-if="!ready && !message" class="space-y-3">
            <div class="h-11 animate-pulse rounded-lg bg-slate-100"></div>
            <div class="grid grid-cols-3 gap-3">
                <div class="h-11 animate-pulse rounded-lg bg-slate-100"></div>
                <div class="h-11 animate-pulse rounded-lg bg-slate-100"></div>
                <div class="h-11 animate-pulse rounded-lg bg-slate-100"></div>
            </div>
        </div>

        <Alert v-if="!ready && message" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertDescription>{{ message }}</AlertDescription>
        </Alert>

        <!-- Not a <form>: the card inputs can never submit natively or end up in a URL.
             sentry-block keeps Sentry session replay from recording the card values. -->
        <div v-show="ready" class="sentry-block space-y-4" @keydown.enter.prevent="submit">
            <div class="grid gap-1.5">
                <Label for="an-card-number" class="text-xs text-slate-600">Card number</Label>
                <Input
                    id="an-card-number"
                    :model-value="number"
                    type="text"
                    inputmode="numeric"
                    autocomplete="cc-number"
                    placeholder="1234 1234 1234 1234"
                    maxlength="23"
                    :aria-invalid="!!fieldErrors.number"
                    @update:model-value="(v) => (number = formatCardNumber(String(v)))"
                />
                <p v-if="fieldErrors.number" class="text-xs text-red-600">{{ fieldErrors.number }}</p>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="grid gap-1.5">
                    <Label for="an-card-expiry" class="text-xs text-slate-600">Expiry</Label>
                    <Input
                        id="an-card-expiry"
                        :model-value="expiry"
                        type="text"
                        inputmode="numeric"
                        autocomplete="cc-exp"
                        placeholder="MM/YY"
                        maxlength="5"
                        :aria-invalid="!!fieldErrors.expiry"
                        @update:model-value="(v) => (expiry = formatExpiry(String(v)))"
                    />
                    <p v-if="fieldErrors.expiry" class="text-xs text-red-600">{{ fieldErrors.expiry }}</p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="an-card-cvv" class="text-xs text-slate-600">CVV</Label>
                    <Input
                        id="an-card-cvv"
                        v-model="cvv"
                        type="password"
                        inputmode="numeric"
                        autocomplete="cc-csc"
                        placeholder="123"
                        maxlength="4"
                        :aria-invalid="!!fieldErrors.cvv"
                    />
                    <p v-if="fieldErrors.cvv" class="text-xs text-red-600">{{ fieldErrors.cvv }}</p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="an-card-zip" class="text-xs text-slate-600">ZIP</Label>
                    <Input
                        id="an-card-zip"
                        v-model="zip"
                        type="text"
                        autocomplete="postal-code"
                        placeholder="ZIP"
                        maxlength="10"
                        :aria-invalid="!!fieldErrors.zip"
                    />
                    <p v-if="fieldErrors.zip" class="text-xs text-red-600">{{ fieldErrors.zip }}</p>
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
