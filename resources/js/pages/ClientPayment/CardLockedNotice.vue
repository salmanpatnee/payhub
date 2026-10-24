<script setup lang="ts">
import { LockKeyhole } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
    lockedUntil: string
    referenceCode: string | null
}>()

const until = computed(() => new Date(props.lockedUntil))

const untilLabel = computed(() =>
    until.value.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
)
</script>

<template>
    <div class="flex flex-col items-center gap-4 py-10 text-center" role="alert">
        <div class="flex size-12 items-center justify-center rounded-full bg-red-50 text-red-600">
            <LockKeyhole class="size-6" />
        </div>
        <div>
            <h1 class="text-lg font-semibold text-slate-900">This payment is paused</h1>
            <p class="mt-1.5 text-sm leading-relaxed text-slate-500">
                There were too many unsuccessful attempts. For your security, card payments for this link are paused until
                about {{ untilLabel }}.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-slate-700">
                Please contact your representative and they will help you complete this payment.
            </p>
            <p v-if="referenceCode" class="mt-3 text-xs text-slate-400">
                Please quote reference <span class="font-mono font-medium text-slate-600">{{ referenceCode }}</span>
            </p>
        </div>
    </div>
</template>
