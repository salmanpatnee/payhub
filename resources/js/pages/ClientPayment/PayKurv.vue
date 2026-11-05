<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3'
import { AlertCircle, X } from 'lucide-vue-next'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { storeConsent } from '@/actions/App/Http/Controllers/ClientPaymentController'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogTitle, DialogClose, DialogDescription } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { formatMoney } from '@/lib/utils'

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
    policies: Policy[]
    hasConsent: boolean
    hasLink: boolean
    returnStatus: 'returned' | null
}>()

// Fixed client messages (spec 0010, AC-11). Kurv text never reaches this page.
const FIXED = {
    busy: 'Your payment is being prepared. Please try again in a moment.',
    unavailable: 'This payment link can no longer be used.',
    tooMany: 'Too many attempts. Please try again later.',
    failed: 'We could not start your payment. Please try again.',
    declined: 'Your payment was not completed. You can try again.',
    consent: 'You must agree to the Terms & Conditions, Refund Policy, and Privacy Policy before proceeding.',
} as const

// 'ready': consent plus Pay now. 'starting': waiting for the checkout endpoint.
// 'waiting': the client came back from Kurv, polling. 'timed-out': 10 minutes since the first return.
type UiState = 'ready' | 'starting' | 'waiting' | 'timed-out'

const STORAGE_KEY = `kurv-confirming:${props.payment.uuid}`
const POLL_INTERVAL_MS = 3000
const GIVE_UP_MS = 10 * 60 * 1000

const uiState = ref<UiState>('ready')
const message = ref<string | null>(null)
const canRetry = ref(false)

const consent = ref(true)
const consentError = ref<string | undefined>(undefined)
const consentBusy = ref(false)
const activePolicy = ref<Policy | null>(null)
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

function blockCopyKeys(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && ['c', 'a', 'x', 's', 'p'].includes(event.key.toLowerCase())) {
        event.preventDefault()
    }
}

function csrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    }
}

// The confirming state and its start time outlive the stripped query string and a reload (AC-9).
// Storage may be blocked, so every access is guarded.
function readStartedAt(): number | null {
    try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY)
        const parsed = raw ? (JSON.parse(raw) as { startedAt?: unknown }) : null

        return parsed && typeof parsed.startedAt === 'number' ? parsed.startedAt : null
    } catch {
        return null
    }
}

function writeStartedAt(value: number | null): void {
    try {
        if (value === null) {
            window.sessionStorage.removeItem(STORAGE_KEY)
        } else {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ startedAt: value }))
        }
    } catch {
        // Storage blocked: the waiting state still works until the next reload.
    }
}

let pollTimer: ReturnType<typeof setInterval> | null = null
let startedAt = 0

function stopPolling(): void {
    if (pollTimer !== null) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

function giveUpIfDue(): boolean {
    // Wall clock time since the first return, not visible time (AC-9).
    if (Date.now() - startedAt >= GIVE_UP_MS) {
        stopPolling()
        uiState.value = 'timed-out'

        return true
    }

    return false
}

async function pollStatus(): Promise<void> {
    if (giveUpIfDue()) {
        return
    }

    // Poll only while the tab is visible.
    if (document.visibilityState !== 'visible') {
        return
    }

    try {
        const response = await fetch(`/pay/${props.payment.uuid}/kurv/status`, { headers: { Accept: 'application/json' } })
        const data = await response.json().catch(() => ({}))

        if (data.state === 'completed') {
            stopPolling()
            writeStartedAt(null)
            router.visit(`/pay/${props.payment.uuid}/success`)

            return
        }

        if (data.state === 'unavailable') {
            stopPolling()
            writeStartedAt(null)
            router.visit(`/pay/${props.payment.uuid}`)

            return
        }

        if (data.state === 'retry') {
            // A decline: forget the waiting state and show Pay now again on the same link.
            stopPolling()
            writeStartedAt(null)
            uiState.value = 'ready'
            message.value = FIXED.declined
            canRetry.value = false

            return
        }
    } catch {
        // Transient network error: keep trying until the give up time.
    }

    giveUpIfDue()
}

function startWaiting(): void {
    uiState.value = 'waiting'
    startedAt = readStartedAt() ?? Date.now()
    writeStartedAt(startedAt)

    if (giveUpIfDue()) {
        return
    }

    if (pollTimer === null) {
        pollTimer = setInterval(pollStatus, POLL_INTERVAL_MS)
        void pollStatus()
    }
}

// Gate for Pay now: records consent for the audit trail before anything is created at Kurv.
async function recordConsent(): Promise<boolean> {
    if (!consent.value) {
        consentError.value = FIXED.consent

        return false
    }

    if (props.hasConsent) {
        return true
    }

    consentBusy.value = true
    let recorded = false
    await useHttp({ accepted: true }).post(storeConsent(props.payment.uuid).url, {
        onSuccess: () => {
            recorded = true
        },
    })
    consentBusy.value = false

    return recorded
}

async function payNow(): Promise<void> {
    message.value = null
    canRetry.value = false
    consentError.value = undefined

    if (!(await recordConsent())) {
        if (consent.value) {
            message.value = FIXED.failed
            canRetry.value = true
        }

        return
    }

    uiState.value = 'starting'

    try {
        const response = await fetch(`/pay/${props.payment.uuid}/kurv/checkout`, {
            method: 'POST',
            headers: csrfHeaders(),
            body: '{}',
        })

        if (!response.ok) {
            uiState.value = 'ready'

            if (response.status === 409) {
                message.value = FIXED.busy
                canRetry.value = true
            } else if (response.status === 422) {
                message.value = FIXED.unavailable
            } else if (response.status === 429) {
                message.value = FIXED.tooMany
            } else {
                message.value = FIXED.failed
                canRetry.value = true
            }

            return
        }

        const data: { url?: string } = await response.json().catch(() => ({}))

        // Only an https address is followed; anything else is a failure, never a redirect.
        if (typeof data.url === 'string' && data.url.startsWith('https://')) {
            window.location.assign(data.url)

            return
        }

        uiState.value = 'ready'
        message.value = FIXED.failed
        canRetry.value = true
    } catch {
        // The client sees the fixed message. No card values exist on this page.
        uiState.value = 'ready'
        message.value = FIXED.failed
        canRetry.value = true
    }
}

function stripReturnQuery(): void {
    const url = new URL(window.location.href)

    if (url.search !== '') {
        // Kurv may append other values to the return address; none of them are used.
        url.search = ''
        window.history.replaceState(window.history.state, '', url.pathname + url.hash)
    }
}

onMounted(() => {
    stripReturnQuery()

    // Back from Kurv, or a reload while confirming. A return without the flag shows Pay now again.
    if (props.returnStatus === 'returned' || readStartedAt() !== null) {
        startWaiting()
    }
})

onBeforeUnmount(() => {
    stopPolling()
})
</script>

<template>
    <PaymentLayout
        :brand="props.brand"
        provider="kurv"
        wide
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
            <template v-if="uiState !== 'timed-out'">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Complete your payment</h1>
                    <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                        You will pay securely by card on our payment partner's page, then come back here. You will see a receipt once the payment is confirmed.
                    </p>
                </div>

                <Alert v-if="uiState === 'waiting'">
                    <AlertCircle class="size-4" />
                    <AlertDescription>We are confirming your payment. Please do not pay again.</AlertDescription>
                </Alert>

                <template v-if="uiState === 'ready' || uiState === 'starting'">
                    <Alert v-if="message" variant="destructive">
                        <AlertCircle class="size-4" />
                        <AlertDescription>{{ message }}</AlertDescription>
                    </Alert>

                    <div class="space-y-3">
                        <div class="flex items-baseline justify-between rounded-lg border border-slate-200 px-4 py-3">
                            <span class="text-sm text-slate-500">Amount due</span>
                            <span class="text-lg font-semibold text-slate-900">{{ formatMoney(payment.amount, payment.currency) }}</span>
                        </div>

                        <Button
                            type="button"
                            class="w-full bg-[var(--btn-color)] text-white hover:bg-[var(--btn-color)]/90 focus-visible:ring-[var(--btn-color)]/50 font-semibold cursor-pointer"
                            :style="{ '--btn-color': brand.secondary_color || brand.primary_color }"
                            :disabled="uiState === 'starting' || consentBusy"
                            @click="payNow"
                        >
                            {{ uiState === 'starting' ? 'Preparing…' : canRetry ? 'Try again' : 'Pay now' }}
                        </Button>
                    </div>
                </template>

                <!-- Compliance footer: consent + policy links -->
                <div class="mt-8 space-y-2.5 border-t border-slate-100 pt-5">
                    <div class="flex items-start gap-2">
                        <Checkbox
                            id="policy-consent"
                            v-model="consent"
                            :aria-invalid="!!consentError"
                            :disabled="consentBusy || uiState !== 'ready'"
                            class="mt-px size-3.5 shrink-0 data-[state=checked]:!bg-slate-400 data-[state=checked]:!border-slate-400 data-[state=checked]:!text-white"
                            @update:model-value="consentError = undefined"
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

            <!-- Past the 10 minute give up time: no more polling; the webhook still completes the payment. -->
            <template v-else>
                <div class="flex flex-col items-center gap-4 py-12 text-center">
                    <div>
                        <h1 class="text-lg font-semibold text-slate-900">Still confirming your payment</h1>
                        <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                            Still confirming, you will get a receipt by email.
                        </p>
                    </div>
                    <Button variant="outline" as-child>
                        <a :href="`/pay/${payment.uuid}`">Check again</a>
                    </Button>
                </div>
            </template>

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
