<script setup lang="ts">
import { AlertCircle } from 'lucide-vue-next'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'

const props = defineProps<{
    payment: { uuid: string }
    /** Records consent first; false means the form must not be started. */
    beforeCharge: () => Promise<boolean>
}>()

const emit = defineEmits<{
    /** The hosted form relayed a transaction id (best effort hint, never trusted). */
    (e: 'transaction', transId: string): void
    /** A token now exists, so the page can start polling the status endpoint. */
    (e: 'ready'): void
}>()

// The token is valid for 15 minutes at the gateway; refresh only on the next focus after 14.
const TOKEN_WINDOW_MS = 14 * 60 * 1000

const FRAME_NAME = 'authorizenet-frame'

const formEl = ref<HTMLFormElement | null>(null)
const tokenEl = ref<HTMLInputElement | null>(null)
const formUrl = ref('')
const loading = ref(true)
const errorMessage = ref<string | null>(null)
// Fixed height that fits card number, expiry, code and the buttons. The gateway's resize
// message never arrives in practice, so this is not driven by it (it can still nudge it).
const frameHeight = ref(400)
const frameLoaded = ref(false)
let submitted = false // an empty iframe fires `load` too; only count the one after the token post
let issuedAt = 0

function xsrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}),
    }
}

/** Ask the server for a token and post it into the iframe. Never retries on its own. */
async function load(): Promise<void> {
    loading.value = true
    errorMessage.value = null

    try {
        if (!(await props.beforeCharge())) {
            loading.value = false

            return
        }

        const response = await fetch(`/pay/${props.payment.uuid}/authorizenet/token`, {
            method: 'POST',
            headers: xsrfHeaders(),
        })
        const data = await response.json().catch(() => ({}))

        if (!response.ok || !data.token) {
            errorMessage.value = response.status === 429
                ? 'Too many attempts. Please wait a while and try again.'
                : (data.message ?? 'We could not start the payment form. Please try again.')
            loading.value = false

            return
        }

        formUrl.value = data.form_url
        issuedAt = Date.now()
        frameLoaded.value = false
        submitted = false

        // Wait one tick so the form action is rendered, then post into the named iframe.
        await new Promise((resolve) => setTimeout(resolve, 0))

        if (tokenEl.value && formEl.value) {
            tokenEl.value.value = data.token
            submitted = true
            formEl.value.submit()
            tokenEl.value.value = '' // the token is used once and not kept in the page
        }

        emit('ready')
    } catch {
        errorMessage.value = 'We could not start the payment form. Please try again.'
    }

    loading.value = false
}

function refreshIfExpired(): void {
    if (document.visibilityState === 'visible' && issuedAt > 0 && Date.now() - issuedAt > TOKEN_WINDOW_MS && !errorMessage.value) {
        void load()
    }
}

/** Optional hint from the hosted form. The page never waits for it. */
function onCommunication(message: string): void {
    const params = new URLSearchParams(message)
    const action = params.get('action')

    if (action === 'resizeWindow') {
        const height = parseInt(params.get('height') ?? '', 10)

        if (height > 200 && height < 3000) {
            frameHeight.value = height
        }
    } else if (action === 'transactResponse') {
        try {
            const body = JSON.parse(params.get('response') ?? '{}')

            if (typeof body.transId === 'string' && /^\d{1,20}$/.test(body.transId) && body.transId !== '0') {
                emit('transaction', body.transId)
            }
        } catch {
            // Not JSON: ignore, polling still finds the transaction.
        }
    } else if (action === 'cancel') {
        void load()
    }
}

onMounted(() => {
    ;(window as unknown as { AuthorizeNetIFrame: unknown }).AuthorizeNetIFrame = { onReceiveCommunication: onCommunication }
    document.addEventListener('visibilitychange', refreshIfExpired)
    window.addEventListener('focus', refreshIfExpired)
    void load()
})

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', refreshIfExpired)
    window.removeEventListener('focus', refreshIfExpired)
})

defineExpose({ load })
</script>

<template>
    <div>
        <Alert v-if="errorMessage" variant="destructive" class="mb-4">
            <AlertCircle class="size-4" />
            <AlertDescription class="flex items-center justify-between gap-3">
                <span>{{ errorMessage }}</span>
                <Button type="button" size="sm" variant="outline" @click="load">Retry</Button>
            </AlertDescription>
        </Alert>

        <!-- Hidden form posts the one time token into Authorize.net's own page in the iframe. -->
        <form ref="formEl" :action="formUrl" method="post" :target="FRAME_NAME" class="hidden">
            <input ref="tokenEl" type="hidden" name="token" value="">
        </form>

        <div
            v-show="!errorMessage"
            class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_8px_24px_-12px_rgba(15,23,42,0.12)]"
            :style="{ height: `${frameHeight}px` }"
        >
            <!-- Skeleton until the hosted form has loaded, so the card is never a blank box. -->
            <div
                v-if="loading || !frameLoaded"
                class="absolute inset-0 z-10 flex flex-col justify-center gap-6 bg-white px-8"
                aria-hidden="true"
            >
                <div class="h-10 animate-pulse rounded-md bg-slate-100" />
                <div class="grid grid-cols-2 gap-6">
                    <div class="h-10 animate-pulse rounded-md bg-slate-100" />
                    <div class="h-10 animate-pulse rounded-md bg-slate-100" />
                </div>
                <div class="flex items-center justify-center gap-2 text-xs text-slate-400">
                    <Spinner class="size-3.5" />
                    <span>Loading secure payment form…</span>
                </div>
            </div>

            <iframe
                :name="FRAME_NAME"
                title="Secure card payment form"
                class="block size-full border-0 transition-opacity duration-300"
                :class="frameLoaded ? 'opacity-100' : 'opacity-0'"
                @load="submitted && (frameLoaded = true)"
            />
        </div>
    </div>
</template>
