<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { XCircle, Ban } from 'lucide-vue-next'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { computed } from 'vue'

const props = defineProps<{
    status: 'completed' | 'failed' | 'cancelled'
    payment?: {
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
    provider?: 'stripe' | 'revolut' | 'square' | 'viva' | 'clover' | 'mpgs' | 'authorizenet'
}>()

const content = computed(() => {
    const map: Record<string, { title: string; description: string; pageTitle: string }> = {
        completed: {
            title:       'Already paid',
            description: `This payment to ${props.brand.name} has already been completed. No further action is needed.`,
            pageTitle:   `Already paid — ${props.brand.name}`,
        },
        failed: {
            title:       'Payment link unavailable',
            description: 'Payment was unsuccessful. Please contact us to arrange a new payment.',
            pageTitle:   `Payment unavailable — ${props.brand.name}`,
        },
        cancelled: {
            title:       'Link no longer active',
            description: 'This payment link has been cancelled. Please contact us if you need assistance.',
            pageTitle:   `Link no longer active — ${props.brand.name}`,
        },
    }
    return map[props.status] ?? map['cancelled']
})

const layoutPayment = computed(() =>
    props.payment
        ? { ...props.payment, status: props.status === 'cancelled' ? undefined : props.status }
        : undefined,
)
</script>

<template>
    <PaymentLayout :brand="props.brand" :provider="props.provider" :payment="layoutPayment">
        <Head :title="content.pageTitle" />

        <div class="unavailable-root">
            <!-- Status mark: animated green seal when paid, plain icon otherwise -->
            <div v-if="status === 'completed'" class="seal-wrap">
                <svg class="check-svg" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle class="ring" cx="40" cy="40" r="33" stroke-width="2.5" />
                    <path class="tick" d="M24 41 L35 52 L57 29" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
            <div v-else-if="status === 'failed'" class="seal-wrap icon-circle bg-red-50">
                <XCircle class="size-9 text-red-500" />
            </div>
            <div v-else class="seal-wrap icon-circle bg-slate-100">
                <Ban class="size-9 text-slate-400" />
            </div>

            <div class="heading-block">
                <h1 class="heading-text">{{ content.title }}</h1>
                <p class="sub-text">{{ content.description }}</p>
            </div>

            <!-- Reference code — what the client quotes when contacting the brand -->
            <div v-if="payment?.reference_code" class="ref-hero" aria-label="Your order code">
                <span class="ref-hero-label">Your Order Code</span>
                <span class="ref-hero-number">{{ payment.reference_code }}</span>
                <span class="ref-hero-hint">Quote this code if you contact us</span>
            </div>

            <!-- Receipt details (paid only) -->
            <dl v-if="status === 'completed' && payment" class="receipt-card">
                <div class="receipt-row">
                    <dt>Status</dt>
                    <dd>
                        <span class="paid-badge">
                            <span class="paid-dot"></span>
                            Paid
                        </span>
                    </dd>
                </div>
                <div v-if="payment.service" class="receipt-row">
                    <dt>Service</dt>
                    <dd>{{ payment.service }}</dd>
                </div>
                <div v-if="payment.package" class="receipt-row">
                    <dt>Package</dt>
                    <dd>{{ payment.package }}</dd>
                </div>
            </dl>

            <p class="help-text">
                Need help? Contact <span class="font-medium text-slate-600">{{ brand.name }}</span> directly.
            </p>
        </div>
    </PaymentLayout>
</template>

<style scoped>
/* Styles mirror Success.vue so the "Already paid" page matches the thank-you page */
.unavailable-root {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 0.25rem 0 0.5rem;
}

.seal-wrap {
    margin-bottom: 1.75rem;
    animation: sealIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) 0.1s both;
}

.icon-circle {
    width: 80px;
    height: 80px;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.check-svg {
    width: 80px;
    height: 80px;
    overflow: visible;
    filter: drop-shadow(0 0 18px rgba(74, 222, 128, 0.4));
}

.ring {
    stroke: #4ade80;
    fill: rgba(74, 222, 128, 0.06);
    stroke-dasharray: 207.3;
    stroke-dashoffset: 207.3;
    transform-origin: 40px 40px;
    transform: rotate(-90deg);
    animation: drawRing 0.65s ease-out 0.2s forwards;
}

.tick {
    stroke: #16a34a;
    fill: none;
    stroke-dasharray: 50;
    stroke-dashoffset: 50;
    animation: drawTick 0.38s ease-out 0.72s forwards;
}

.heading-block {
    margin-bottom: 1.625rem;
    animation: riseIn 0.48s ease-out 0.38s both;
}

.heading-text {
    font-size: 1.6rem;
    font-weight: 700;
    letter-spacing: -0.03em;
    color: #0f172a;
    line-height: 1.15;
    margin-bottom: 0.45rem;
}

.sub-text {
    font-size: 0.875rem;
    color: #64748b;
    line-height: 1.6;
    max-width: 32ch;
    margin: 0 auto;
}

.ref-hero {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    border: 2px dashed #94a3b8;
    border-radius: 10px;
    padding: 22px 24px 20px;
    margin-bottom: 1.25rem;
    background:
        radial-gradient(circle, #d1d5db 1px, transparent 1px) 0 0 / 20px 20px,
        linear-gradient(160deg, #f8fafc 0%, #eef2f7 100%);
    box-shadow: inset 0 1px 4px rgba(0, 0, 0, 0.04);
    animation: riseIn 0.48s ease-out 0.44s both;
}

.ref-hero-label {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #64748b;
}

.ref-hero-number {
    font-family: 'SFMono-Regular', 'Consolas', 'Liberation Mono', monospace;
    font-size: 2.5rem;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: #0f172a;
    line-height: 1;
    overflow-wrap: anywhere;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

.ref-hero-hint {
    font-size: 10px;
    color: #94a3b8;
    letter-spacing: 0.03em;
    margin-top: 3px;
}

.receipt-card {
    width: 100%;
    background: #ffffff;
    border: 1px solid hsl(0, 0%, 92.8%);
    border-radius: 6px;
    overflow: hidden;
    margin: 0 0 1.5rem;
    animation: riseIn 0.48s ease-out 0.52s both;
}

.receipt-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 20px;
    gap: 12px;
    border-bottom: 1px solid #f1f5f9;
}
.receipt-row:last-child {
    border-bottom: none;
}

.receipt-row dt {
    font-size: 13px;
    color: #374151;
    font-weight: 500;
    flex-shrink: 0;
    margin: 0;
}

.receipt-row dd {
    font-size: 13px;
    font-weight: 500;
    color: #1e293b;
    text-align: right;
    text-transform: capitalize;
    margin: 0;
    max-width: 60%;
}

.paid-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #15803d;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    padding: 3px 10px 3px 8px;
    border-radius: 999px;
}

.paid-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #4ade80;
    flex-shrink: 0;
    animation: pulseDot 2.4s ease-in-out 1.3s infinite;
}

.help-text {
    font-size: 0.75rem;
    color: #94a3b8;
    animation: riseIn 0.48s ease-out 0.6s both;
}

@keyframes sealIn {
    from { opacity: 0; transform: scale(0.55); }
    to   { opacity: 1; transform: scale(1); }
}
@keyframes drawRing { to { stroke-dashoffset: 0; } }
@keyframes drawTick { to { stroke-dashoffset: 0; } }
@keyframes pulseDot {
    0%, 100% { opacity: 1;   transform: scale(1);   }
    50%      { opacity: 0.5; transform: scale(0.75); }
}
@keyframes riseIn {
    from { opacity: 0; transform: translateY(13px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
