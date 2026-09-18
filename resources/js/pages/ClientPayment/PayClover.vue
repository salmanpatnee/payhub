<script setup lang="ts">
import { ref, onBeforeUnmount } from 'vue'
import { Head, useHttp } from '@inertiajs/vue3'
import { AlertCircle, X } from 'lucide-vue-next'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Spinner } from '@/components/ui/spinner'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogTitle, DialogClose, DialogDescription } from '@/components/ui/dialog'
import { storeConsent } from '@/actions/App/Http/Controllers/ClientPaymentController'
import CloverPaymentForm from './CloverPaymentForm.vue'

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
    cloverAccount: {
        merchant_id: string
        api_access_key: string
        environment: 'sandbox' | 'production'
    }
    policies: Policy[]
}>()

// 'form': entering card details. 'processing': the synchronous charge response was
// unknown (a timeout/5xx/unclassifiable body) — poll Payment.status instead of the
// browser trying to guess. 'timed-out': polled for 2 minutes with no resolution yet.
type UiState = 'form' | 'processing' | 'timed-out'
const uiState = ref<UiState>('form')
const processingMessage = ref<string | null>(null)

const consent       = ref(true)
const consentError  = ref<string | undefined>(undefined)
const consentBusy   = ref(false)
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

/**
 * Gate for CloverPaymentForm's submit(): records consent for the audit trail before
 * any card token is created. Returns false (and surfaces an inline reason) if consent
 * is missing or couldn't be recorded, so the form never tokenizes/charges without it.
 */
async function beforeCharge(): Promise<boolean> {
    if (!consent.value) {
        consentError.value = 'You must agree to the Terms & Conditions, Refund Policy, and Privacy Policy before proceeding.'
        return false
    }

    consentBusy.value = true
    let consentRecorded = false
    await useHttp({ accepted: true }).post(storeConsent(props.payment.uuid).url, {
        onSuccess: () => { consentRecorded = true },
    })
    consentBusy.value = false

    return consentRecorded
}

const POLL_INTERVAL_MS = 3000
const POLL_TIMEOUT_MS = 2 * 60 * 1000
let pollTimer: ReturnType<typeof setInterval> | null = null
let pollDeadline = 0

function stopPolling(): void {
    if (pollTimer !== null) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

async function pollStatus(): Promise<void> {
    try {
        const response = await fetch(`/pay/${props.payment.uuid}/clover/status`, {
            headers: { Accept: 'application/json' },
        })
        const data = await response.json().catch(() => ({}))

        if (data.status === 'completed') {
            stopPolling()
            window.location.href = `/pay/${props.payment.uuid}/success`
            return
        }

        if (data.status === 'failed') {
            stopPolling()
            processingMessage.value = 'Your card was declined. You can try again with a different card.'
            uiState.value = 'form'
            return
        }

        if (data.status === 'cancelled') {
            stopPolling()
            window.location.href = `/pay/${props.payment.uuid}`
            return
        }
    } catch {
        // Transient network error while polling — keep trying until the deadline.
    }

    if (Date.now() >= pollDeadline) {
        stopPolling()
        uiState.value = 'timed-out'
    }
}

function onUnknownOutcome(): void {
    processingMessage.value = null
    uiState.value = 'processing'
    pollDeadline = Date.now() + POLL_TIMEOUT_MS
    pollTimer = setInterval(pollStatus, POLL_INTERVAL_MS)
    pollStatus()
}

onBeforeUnmount(stopPolling)
</script>

<template>
    <PaymentLayout
        :brand="props.brand"
        provider="clover"
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

        <div class="form-content space-y-6" :style="{ '--brand-primary': brand.primary_color }">
            <template v-if="uiState === 'form'">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Complete your payment</h1>
                    <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                        Enter your card details below to pay securely.
                    </p>
                </div>

                <Alert v-if="processingMessage" variant="destructive">
                    <AlertCircle class="size-4" />
                    <AlertDescription>{{ processingMessage }}</AlertDescription>
                </Alert>

                <CloverPaymentForm
                    :payment="{ uuid: payment.uuid, amount: payment.amount, currency: payment.currency }"
                    :clover-account="cloverAccount"
                    :before-charge="beforeCharge"
                    @unknown="onUnknownOutcome"
                />

                <!-- Compliance footer — consent + policy links, intentionally low-emphasis boilerplate -->
                <div class="mt-8 space-y-2.5 border-t border-slate-100 pt-5">
                    <div class="flex items-start gap-2">
                        <Checkbox
                            id="policy-consent"
                            v-model="consent"
                            :aria-invalid="!!consentError"
                            :disabled="consentBusy"
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
            </template>

            <!-- Unknown-outcome processing state — polls Payment.status (AC-9). -->
            <template v-else-if="uiState === 'processing'">
                <div class="flex flex-col items-center gap-4 py-12 text-center">
                    <Spinner class="size-8 text-slate-400" />
                    <div>
                        <h1 class="text-lg font-semibold text-slate-900">Confirming your payment…</h1>
                        <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                            This can take a few moments. Please don't close this page.
                        </p>
                    </div>
                </div>
            </template>

            <!-- Past the 2-minute poll window — terminal state, no more polling. -->
            <template v-else>
                <div class="flex flex-col items-center gap-4 py-12 text-center">
                    <div>
                        <h1 class="text-lg font-semibold text-slate-900">Still confirming your payment</h1>
                        <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                            This is taking longer than usual. You'll receive a receipt by email once it's confirmed.
                        </p>
                    </div>
                    <Button variant="outline" as-child>
                        <a :href="`/pay/${payment.uuid}`">Check again</a>
                    </Button>
                </div>
            </template>

            <!-- Policy viewer — Material 3 / Google-style dialog. Native HTML, read-only, fully legible. -->
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
</style>
