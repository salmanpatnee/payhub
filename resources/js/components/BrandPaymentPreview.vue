<script setup lang="ts">
import { LockIcon, X } from 'lucide-vue-next'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import PaymentLayout from '@/layouts/PaymentLayout.vue'

defineProps<{
    name: string
    primaryColor: string
    secondaryColor: string
    logoUrl: string | null
}>()

const open = defineModel<boolean>('open')
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="fixed inset-0 top-0 left-0 z-50 w-full h-svh max-w-none sm:max-w-none translate-x-0 translate-y-0 rounded-none border-0 p-0 gap-0 overflow-y-auto"
        >
            <DialogTitle class="sr-only">Payment page preview</DialogTitle>

            <!-- Floating close button over the dark sidebar -->
            <button
                type="button"
                class="fixed top-4 right-4 z-50 inline-flex items-center gap-1.5 rounded-md border border-white/20 bg-black/40 px-3 py-1.5 text-xs font-medium text-white backdrop-blur transition-colors hover:bg-black/60"
                @click="open = false"
            >
                <X class="size-3.5" />
                Close preview
            </button>

            <PaymentLayout
                :brand="{
                    name: name || 'Your brand',
                    slug: 'preview',
                    logo_url: logoUrl,
                    primary_color: primaryColor,
                    secondary_color: secondaryColor,
                }"
                :payment="{
                    reference_code: '#123456',
                    amount: 9999,
                    currency: 'usd',
                    service: 'Website Design',
                    package: 'Premium',
                    status: 'pending',
                }"
            >
                <div class="form-content space-y-6">
                    <!-- Header (mirrors Pay.vue) -->
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Complete your payment</h1>
                        <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                            Enter your card details below to pay securely.
                        </p>
                    </div>

                    <!-- Card-input skeleton (real Stripe Elements unavailable in preview) -->
                    <div class="space-y-3">
                        <div class="skeleton-row h-12 rounded-lg"></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="skeleton-row h-12 rounded-lg"></div>
                            <div class="skeleton-row h-12 rounded-lg"></div>
                        </div>
                        <div class="skeleton-row h-12 rounded-lg"></div>
                        <div class="skeleton-row h-12 rounded-lg mt-2 opacity-75"></div>
                    </div>

                    <!-- Pay button preview -->
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-md py-3 text-sm font-semibold tracking-wide text-white opacity-90"
                        :style="{ backgroundColor: primaryColor }"
                    >
                        Pay $99.99
                    </button>

                    <p class="flex items-center justify-center gap-1.5 text-xs text-slate-600 text-center leading-relaxed">
                        <LockIcon class="size-3 shrink-0" />
                        Your card details are never stored · 256-bit SSL
                    </p>
                </div>
            </PaymentLayout>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.skeleton-row {
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s ease-in-out infinite;
}

@keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>
