<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import PaymentLayout from '@/layouts/PaymentLayout.vue'

const props = defineProps<{
    payment: {
        uuid: string
        reference_code: number | null
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
}>()

// 16 confetti particles — static positions computed once
const particles = Array.from({ length: 16 }, (_, i) => ({
    id: i,
    style: [
        `--angle: ${Math.round((i / 16) * 360)}deg`,
        `--dist: ${48 + (i % 4) * 14}px`,
        `--delay: ${(i * 0.05).toFixed(3)}s`,
        `--color: ${['#4ade80', '#86efac', '#fbbf24', '#a78bfa', '#60a5fa', '#f472b6', '#34d399', '#fb923c'][i % 8]}`,
        `--size: ${4 + (i % 4) * 2}px`,
    ].join('; '),
}))
</script>

<template>
    <PaymentLayout
        :brand="props.brand"
        :payment="{
            uuid: payment.uuid,
            reference_code: payment.reference_code,
            amount: payment.amount,
            currency: payment.currency,
            service: payment.service,
            package: payment.package,
            status: 'completed',
        }"
    >
        <Head :title="`Payment received — ${props.brand.name}`" />

        <div class="success-root">

            <!-- Confetti burst — radiates from checkmark centre -->
            <div class="confetti-stage" aria-hidden="true">
                <span
                    v-for="p in particles"
                    :key="p.id"
                    class="particle"
                    :style="p.style"
                ></span>
            </div>

            <!-- Animated SVG checkmark seal -->
            <div class="seal-wrap">
                <svg class="check-svg" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle class="ring" cx="40" cy="40" r="33" stroke-width="2.5" />
                    <path class="tick" d="M24 41 L35 52 L57 29" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>

            <!-- Heading block -->
            <div class="heading-block">
                <h1 class="heading-text">Payment Confirmed</h1>
                <p class="sub-text">
                    Your payment to <strong>{{ brand.name }}</strong> was processed successfully.
                </p>
            </div>

            <!-- Reference hero — the one thing the client needs to note -->
            <div v-if="payment.reference_code !== null" class="ref-hero" aria-label="Your order code">
                <span class="ref-hero-label">Your Order Code</span>
                <div class="ref-hero-value">
                    <span class="ref-hero-hash">#</span><span class="ref-hero-number">{{ String(payment.reference_code).padStart(6, '0') }}</span>
                </div>
                <span class="ref-hero-hint">Keep this number for your records</span>
            </div>

            <!-- Receipt details -->
            <dl class="receipt-card">
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

            <!-- Footer note -->
            <p class="footer-note">
                A confirmation receipt has been sent to your email address.
            </p>

        </div>
    </PaymentLayout>
</template>

<style scoped>

/* ─── Root ───────────────────────────────────────────── */
.success-root {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 0.25rem 0 0.5rem;
}

/* ─── Confetti ───────────────────────────────────────── */
.confetti-stage {
    position: absolute;
    top: 40px;   /* align with checkmark centre */
    left: 50%;
    width: 0;
    height: 0;
    pointer-events: none;
    z-index: 0;
}

.particle {
    position: absolute;
    top: 0;
    left: 0;
    width: var(--size);
    height: var(--size);
    background: var(--color);
    border-radius: 50%;
    opacity: 0;
    transform-origin: 0 0;
    animation: burst 1.1s cubic-bezier(0.15, 0.8, 0.4, 1) var(--delay) forwards;
}

/* Every third particle is a tiny square instead of a circle */
.particle:nth-child(3n) {
    border-radius: 2px;
}

@keyframes burst {
    0%   { opacity: 1;   transform: rotate(var(--angle)) translateX(0)              scale(1);   }
    70%  { opacity: 0.7; transform: rotate(var(--angle)) translateX(var(--dist))    scale(0.9); }
    100% { opacity: 0;   transform: rotate(var(--angle)) translateX(calc(var(--dist) * 1.3)) scale(0.2); }
}

/* ─── Seal ───────────────────────────────────────────── */
.seal-wrap {
    position: relative;
    z-index: 1;
    margin-bottom: 1.75rem;
    animation: sealIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) 0.1s both;
}

.check-svg {
    width: 80px;
    height: 80px;
    overflow: visible;
    filter: drop-shadow(0 0 18px rgba(74, 222, 128, 0.4));
}

/* Circle draws from 12 o'clock */
.ring {
    stroke: #4ade80;
    fill: rgba(74, 222, 128, 0.06);
    stroke-dasharray: 207.3;
    stroke-dashoffset: 207.3;
    transform-origin: 40px 40px;
    transform: rotate(-90deg);
    animation: drawRing 0.65s ease-out 0.2s forwards;
}

/* Tick draws in after ring completes */
.tick {
    stroke: #16a34a;
    fill: none;
    stroke-dasharray: 50;
    stroke-dashoffset: 50;
    animation: drawTick 0.38s ease-out 0.72s forwards;
}

@keyframes sealIn {
    from { opacity: 0; transform: scale(0.55); }
    to   { opacity: 1; transform: scale(1); }
}
@keyframes drawRing {
    to { stroke-dashoffset: 0; }
}
@keyframes drawTick {
    to { stroke-dashoffset: 0; }
}

/* ─── Heading ────────────────────────────────────────── */
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
    max-width: 28ch;
    margin: 0 auto;
}

.sub-text strong {
    color: #334155;
    font-weight: 600;
}

/* ─── Receipt card ───────────────────────────────────── */
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

/* ─── Paid badge ─────────────────────────────────────── */
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

@keyframes pulseDot {
    0%, 100% { opacity: 1;   transform: scale(1);   }
    50%       { opacity: 0.5; transform: scale(0.75); }
}

/* ─── Reference hero ─────────────────────────────────── */
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

.ref-hero-value {
    display: flex;
    align-items: baseline;
    gap: 0;
    line-height: 1;
}

.ref-hero-hash {
    font-family: 'SFMono-Regular', 'Consolas', 'Liberation Mono', monospace;
    font-size: 1.45rem;
    font-weight: 700;
    color: #16a34a;
    margin-right: 3px;
    opacity: 0.9;
}

.ref-hero-number {
    font-family: 'SFMono-Regular', 'Consolas', 'Liberation Mono', monospace;
    font-size: 2.5rem;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: #0f172a;
    line-height: 1;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

.ref-hero-hint {
    font-size: 10px;
    color: #94a3b8;
    letter-spacing: 0.03em;
    margin-top: 3px;
}

/* ─── Footer ─────────────────────────────────────────── */
.footer-note {
    font-size: 0.72rem;
    color: #475569;
    line-height: 1.55;
    animation: riseIn 0.48s ease-out 0.68s both;
}

/* ─── Shared keyframes ───────────────────────────────── */
@keyframes riseIn {
    from { opacity: 0; transform: translateY(13px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
