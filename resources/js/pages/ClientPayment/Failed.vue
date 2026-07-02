<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { XCircle } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import PaymentLayout from '@/layouts/PaymentLayout.vue'

const props = defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
    }
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
    provider?: 'stripe' | 'revolut' | 'square'
}>()
</script>

<template>
    <PaymentLayout
        :brand="props.brand"
        :provider="props.provider"
        :payment="{
            uuid: payment.uuid,
            amount: payment.amount,
            currency: payment.currency,
            status: 'failed',
        }"
    >
        <Head :title="`Payment unsuccessful — ${props.brand.name}`" />

        <div class="page-content space-y-7">
            <!-- Icon + heading -->
            <div class="text-center space-y-4">
                <div class="icon-wrap mx-auto flex h-18 w-18 items-center justify-center rounded-full bg-red-50">
                    <XCircle class="size-9 text-red-500" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Payment Failed</h1>
                    <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                        We weren't able to process your payment. Please try again below.
                    </p>
                </div>
            </div>

            <!-- Reason cards -->
            <div class="grid grid-cols-2 gap-3">
                <div class="reason-card">
                    <p class="reason-title">Why did this happen?</p>
                    <ul class="reason-list">
                        <li>Card was declined</li>
                        <li>Insufficient funds</li>
                        <li>Expired card details</li>
                    </ul>
                </div>
                <div class="reason-card">
                    <p class="reason-title">To resolve this:</p>
                    <ul class="reason-list">
                        <li>Check your card details</li>
                        <li>Try a different card</li>
                        <li>Contact your bank</li>
                    </ul>
                </div>
            </div>

            <!-- D-05: "Try again" link back to /pay/{uuid} — fresh form, new PI on load -->
            <Button
                as-child
                size="lg"
                class="w-full bg-[var(--brand-primary)] text-white hover:bg-[var(--brand-primary)]/90 focus-visible:ring-[var(--brand-primary)]/50 font-semibold tracking-wide"
            >
                <a :href="`/pay/${payment.uuid}`">Try again</a>
            </Button>
        </div>
    </PaymentLayout>
</template>

<style scoped>
.page-content {
    animation: contentIn 0.4s ease-out 0.2s both;
}

.icon-wrap {
    animation: errorBounce 0.55s cubic-bezier(0.36, 0.07, 0.19, 0.97) 0.25s both;
}

@keyframes contentIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes errorBounce {
    0%   { opacity: 0; transform: scale(0.4); }
    55%  { opacity: 1; transform: scale(1.12); }
    75%  { transform: scale(0.94) rotate(-2deg); }
    90%  { transform: scale(1.03) rotate(1deg); }
    100% { transform: scale(1) rotate(0); opacity: 1; }
}

.reason-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
}

.reason-title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #334155;
    margin-bottom: 10px;
}

.reason-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.reason-list li {
    font-size: 12px;
    color: #64748b;
    padding-left: 10px;
    position: relative;
    line-height: 1.4;
}

.reason-list li::before {
    content: '·';
    position: absolute;
    left: 0;
    color: #94a3b8;
    font-weight: 700;
}
</style>
