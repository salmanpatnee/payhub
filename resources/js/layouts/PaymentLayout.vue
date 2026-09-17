<script setup lang="ts">
import { Landmark, LockIcon } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
    payment?: {
        uuid?: string
        reference_code?: string | null
        amount?: number
        currency?: string
        service?: string | null
        package?: string | null
        status?: 'pending' | 'completed' | 'failed'
    }
    /** Payment processor shown in the trust footer. Defaults to Stripe. */
    provider?: 'stripe' | 'revolut' | 'square' | 'viva' | 'clover'
}>()

function formatAmount(cents: number, currency: string): string {
    const formatter = new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    )
    return formatter.formatToParts(cents / 100).map((part, i, parts) => {
        if (part.type === 'currency' && parts[i + 1]?.type !== 'literal') {
            return part.value + ' '
        }
        return part.value
    }).join('')
}

const statusConfig = computed(() => {
    const s = props.payment?.status
    if (s === 'completed') return {
        label: 'Paid',
        dot: '#4ade80',
        style: 'background: rgba(74,222,128,0.15); color: #bbf7d0; border: 1px solid rgba(74,222,128,0.3);',
    }
    if (s === 'failed') return {
        label: 'Failed',
        dot: '#f87171',
        style: 'background: rgba(248,113,113,0.15); color: #fecaca; border: 1px solid rgba(248,113,113,0.3);',
    }
    return {
        label: 'Pending',
        dot: '#fbbf24',
        style: 'background: rgba(251,191,36,0.15); color: #fde68a; border: 1px solid rgba(251,191,36,0.3);',
    }
})
</script>

<template>
    <div
        :data-brand="brand.slug"
        :style="`--brand-primary: ${brand.primary_color}; --brand-secondary: ${brand.secondary_color}`"
        class="min-h-svh flex flex-col lg:flex-row"
    >
        <!-- Sidebar -->
        <aside class="brand-sidebar relative overflow-hidden text-white flex-shrink-0 lg:w-96 lg:sticky lg:top-0 lg:h-svh flex flex-col">
            <div class="relative z-10 flex flex-col flex-1 p-8 lg:p-10 min-h-full">

                <!-- Brand identity -->
                <div class="brand-header mb-8 lg:mb-10">
                    <img
                        v-if="brand.logo_url"
                        :src="brand.logo_url"
                        :alt="brand.name"
                        class="w-auto object-contain max-w-[180px]"
                    />
                    <span v-else class="text-2xl font-bold tracking-tight leading-none">{{ brand.name }}</span>
                </div>

                <!-- Payment summary -->
                <template v-if="payment">
                    <div class="summary-card flex flex-col gap-5 flex-1">
                        <!-- Status -->
                        <div v-if="payment.status">
                            <span
                                :style="statusConfig.style"
                                class="status-pill"
                            >
                                <span :style="`background: ${statusConfig.dot}`" class="status-dot"></span>
                                {{ statusConfig.label }}
                            </span>
                        </div>

                        <!-- Order code -->
                        <div v-if="payment.reference_code != null || payment.uuid">
                            <p class="field-label">Order Code</p>
                            <p class="field-value ref-code mt-1">
                                <template v-if="payment.reference_code != null">{{ payment.reference_code }}</template>
                                <template v-else>{{ payment.uuid!.slice(0, 8).toUpperCase() }}</template>
                            </p>
                        </div>

                        <!-- Service -->
                        <div v-if="payment.service">
                            <p class="field-label">Service</p>
                            <p class="field-value mt-1">{{ payment.service }}</p>
                        </div>

                        <!-- Package -->
                        <div v-if="payment.package">
                            <p class="field-label">Package</p>
                            <p class="field-value mt-1">{{ payment.package }}</p>
                        </div>

                        <!-- Push amount to bottom -->
                        <div class="flex-1"></div>

                        <!-- Amount hero -->
                        <div v-if="payment.amount && payment.currency" class="pt-5 border-t border-white/10">
                            <p class="field-label mb-2">Total due</p>
                            <p class="amount-hero">{{ formatAmount(payment.amount, payment.currency) }}</p>
                        </div>
                    </div>
                </template>

                <div v-else class="flex-1"></div>

                <!-- Trust footer -->
                <footer class="mt-auto pt-7 border-t border-white/10 space-y-2.5">
                    <div class="trust-row">
                        <LockIcon class="size-3.5 shrink-0" />
                        <span>256-bit SSL encrypted</span>
                    </div>
                    <div class="trust-row">
                        <!-- Stripe S mark -->
                        <svg v-if="provider !== 'revolut' && provider !== 'square' && provider !== 'viva' && provider !== 'clover'" class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.591-7.305z"/>
                        </svg>
                        <!-- Square mark -->
                        <svg v-else-if="provider === 'square'" class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M4.01 3.5A2.51 2.51 0 0 0 1.5 6.01v11.98A2.51 2.51 0 0 0 4.01 20.5h15.98a2.51 2.51 0 0 0 2.51-2.51V6.01a2.51 2.51 0 0 0-2.51-2.51H4.01zM5 6.5h14v11H5v-11zm4 3a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1H9z"/>
                        </svg>
                        <!-- Clover four-petal mark -->
                        <svg v-else-if="provider === 'clover'" class="size-3.5 shrink-0" viewBox="0 0 40 40" fill="currentColor" aria-hidden="true">
                            <path d="M18.0259 10.433C18.0259 5.45897 13.9888 1.42017 9.01114 1.42017C4.03464 1.42017 0.000244141 5.45382 0.000244141 10.4279C0.000244141 15.4019 4.03457 19.4459 9.0134 19.4459H18.0259L18.0259 10.433Z M20.5541 10.4331C20.5541 5.45903 24.5912 1.42023 29.5689 1.42023C34.5454 1.42023 38.5798 5.45388 38.5798 10.4279C38.5798 15.402 34.5454 19.4459 29.5666 19.4459L20.5541 19.4459L20.5541 10.4331Z M20.5541 30.9872C20.5541 35.9661 24.5901 40 29.5697 40C34.5439 40 38.5798 35.9691 38.5798 30.9901C38.5798 26.0159 34.5439 21.9743 29.5666 21.9743L20.5541 21.9743V30.9872Z M18.0257 30.9872C18.0257 35.9661 13.9897 40 9.01013 40C4.03601 40 0 35.9691 0 30.9901C0 26.0159 4.03593 21.9743 9.01319 21.9743H18.0257V30.9872ZM9.01013 37.4585C12.5872 37.4585 15.4974 34.5569 15.4974 30.9901V24.504H9.01836C5.4397 24.504 2.52802 27.4249 2.52802 30.9901C2.52802 34.5569 5.43577 37.4585 9.01013 37.4585Z" />
                        </svg>
                        <!-- Revolut and Viva share a generic landmark mark (neither has an in-page SDK) -->
                        <Landmark v-else class="size-3.5 shrink-0" />
                        <span>Powered by {{ provider === 'revolut' ? 'Revolut' : provider === 'square' ? 'Square' : provider === 'viva' ? 'Viva' : provider === 'clover' ? 'Clover' : 'Stripe' }}</span>
                    </div>
                    <p class="safe-copy">Your card details are never stored on our servers</p>
                </footer>
            </div>
        </aside>

        <!-- Main content -->
        <main class="form-panel flex-1 flex flex-col items-center justify-center px-6 py-14 lg:px-14">
            <div class="w-full max-w-lg">
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
.brand-sidebar {
    background:
        radial-gradient(ellipse 80% 55% at 5% 5%, rgba(255,255,255,0.14) 0%, transparent 100%),
        radial-gradient(ellipse 65% 50% at 95% 95%, rgba(0,0,0,0.28) 0%, transparent 100%),
        var(--brand-primary);
    animation: sidebarIn 0.48s cubic-bezier(0.22, 1, 0.36, 1) both;
}

/* Subtle dot-grid texture */
.brand-sidebar::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle, rgba(255,255,255,0.065) 1px, transparent 1px);
    background-size: 22px 22px;
    pointer-events: none;
    z-index: 0;
}

.form-panel {
    background: #f8fafc;
    animation: panelIn 0.48s cubic-bezier(0.22, 1, 0.36, 1) 0.1s both;
}

.brand-header {
    animation: fadeDown 0.4s ease-out 0.15s both;
}

@keyframes sidebarIn {
    from { opacity: 0; transform: translateX(-24px); }
    to   { opacity: 1; transform: translateX(0); }
}

@keyframes panelIn {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes fadeDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Glass summary card */
.summary-card {
    background: rgba(0, 0, 0, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    padding: 1.375rem 1.5rem;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.055em;
    text-transform: uppercase;
    padding: 3px 10px 3px 8px;
    border-radius: 999px;
    flex-shrink: 0;
}

.status-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    flex-shrink: 0;
}

.field-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.65);
}

.field-value {
    font-size: 14px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.92);
    line-height: 1.45;
    text-transform: capitalize;
}

.field-value.ref-code {
    font-family: 'SFMono-Regular', 'Consolas', 'Liberation Mono', monospace;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: none;
}

.amount-hero {
    font-size: clamp(2rem, 4.5vw, 2.75rem);
    font-weight: 800;
    letter-spacing: -0.035em;
    line-height: 1.05;
    color: #ffffff;
}

.trust-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.65);
}

.safe-copy {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.5);
    letter-spacing: 0.01em;
    padding-top: 2px;
}
</style>
