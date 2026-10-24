<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
    lockedUntil: string
    referenceCode: string | null
}>()

const untilLabel = computed(() =>
    new Date(props.lockedUntil).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
)
</script>

<template>
    <div class="paused-root" role="alert">
        <!-- Drawn lock seal, same motion as the checkmark on the payment confirmed page -->
        <div class="seal-wrap">
            <svg class="seal-svg" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle class="ring" cx="40" cy="40" r="33" stroke-width="2.5" />
                <rect class="lock-body" x="28" y="39" width="24" height="16" rx="3" stroke-width="3" stroke-linejoin="round" />
                <path class="lock-shackle" d="M33 39 V34 a7 7 0 0 1 14 0 V39" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>

        <div class="heading-block">
            <h1 class="heading-text">Payment Paused</h1>
            <p class="sub-text">
                Your card did not go through a few times, so we paused this payment for a while.
                <strong>You have not been charged.</strong>
            </p>
        </div>

        <div v-if="referenceCode" class="ref-hero" aria-label="Your order code">
            <span class="ref-hero-label">Your Order Code</span>
            <span class="ref-hero-number">{{ referenceCode }}</span>
            <span class="ref-hero-hint">Give this code to your representative</span>
        </div>

        <dl class="receipt-card">
            <div class="receipt-row">
                <dt>Status</dt>
                <dd>
                    <span class="paused-badge">
                        <span class="paused-dot"></span>
                        Paused
                    </span>
                </dd>
            </div>
            <div class="receipt-row">
                <dt>Try again after</dt>
                <dd>{{ untilLabel }}</dd>
            </div>
        </dl>
    </div>
</template>

<style scoped>
.paused-root {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 0.25rem 0 0.5rem;
}

/* ─── Seal ───────────────────────────────────────────── */
.seal-wrap {
    margin-bottom: 1.75rem;
    animation: sealIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) 0.1s both;
}

.seal-svg {
    width: 80px;
    height: 80px;
    overflow: visible;
    filter: drop-shadow(0 0 18px rgba(245, 158, 11, 0.35));
}

.ring {
    stroke: #f59e0b;
    fill: rgba(245, 158, 11, 0.06);
    stroke-dasharray: 207.3;
    stroke-dashoffset: 207.3;
    transform-origin: 40px 40px;
    transform: rotate(-90deg);
    animation: draw 0.65s ease-out 0.2s forwards;
}

.lock-body {
    stroke: #b45309;
    fill: none;
    stroke-dasharray: 80;
    stroke-dashoffset: 80;
    animation: draw 0.5s ease-out 0.7s forwards;
}

.lock-shackle {
    stroke: #b45309;
    fill: none;
    stroke-dasharray: 40;
    stroke-dashoffset: 40;
    animation: draw 0.4s ease-out 1s forwards;
}

@keyframes sealIn {
    from { opacity: 0; transform: scale(0.55); }
    to   { opacity: 1; transform: scale(1); }
}
@keyframes draw {
    to { stroke-dashoffset: 0; }
}
@keyframes riseIn {
    from { opacity: 0; transform: translateY(13px); }
    to   { opacity: 1; transform: translateY(0); }
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
    max-width: 32ch;
    margin: 0 auto;
}

.sub-text strong {
    color: #334155;
    font-weight: 600;
}

/* ─── Order code ─────────────────────────────────────── */
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
    font-size: clamp(1.7rem, 7vw, 2.1rem);
    font-weight: 800;
    letter-spacing: 0.06em;
    color: #0f172a;
    line-height: 1;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    overflow-wrap: anywhere;
}

.ref-hero-hint {
    font-size: 10.5px;
    color: #94a3b8;
    letter-spacing: 0.03em;
    margin-top: 3px;
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
    margin: 0;
    max-width: 62%;
    font-variant-numeric: tabular-nums;
}

.paused-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #b45309;
    background: #fffbeb;
    border: 1px solid #fde68a;
    padding: 3px 10px 3px 8px;
    border-radius: 999px;
}

.paused-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #f59e0b;
    flex-shrink: 0;
}

@media (prefers-reduced-motion: reduce) {
    .seal-wrap,
    .heading-block,
    .ref-hero,
    .receipt-card {
        animation: none;
    }

    .ring,
    .lock-body,
    .lock-shackle {
        animation: none;
        stroke-dashoffset: 0;
    }
}
</style>
