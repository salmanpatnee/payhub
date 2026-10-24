<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LockKeyhole, LockOpen } from 'lucide-vue-next';
import { ref } from 'vue';
import PaymentProviderBadge from '@/components/PaymentProviderBadge.vue';
import { Button } from '@/components/ui/button';
import { formatMoney, formatReferenceCode } from '@/lib/utils';
import type { LockedPaymentRow } from '@/types/dashboard';

defineProps<{
    rows: LockedPaymentRow[];
}>();

const resetting = ref<string | null>(null);

function timeLeft(iso: string): string {
    const minutes = Math.max(1, Math.ceil((new Date(iso).getTime() - Date.now()) / 60_000));

    return `${minutes} min left`;
}

function resetLock(uuid: string) {
    resetting.value = uuid;
    router.patch(`/payments/${uuid}/reset-lock`, {}, {
        preserveScroll: true,
        onFinish: () => {
            resetting.value = null;
        },
    });
}
</script>

<template>
    <div class="rounded-xl border border-red-200 bg-red-50/60 shadow-sm dark:border-red-900/60 dark:bg-red-950/20">
        <div class="flex items-start gap-2 border-b border-red-200/70 px-4 py-3 dark:border-red-900/50">
            <LockKeyhole class="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-400" />
            <div>
                <h3 class="text-sm font-semibold text-red-700 dark:text-red-300">
                    Locked payments ({{ rows.length }})
                </h3>
                <p class="text-xs text-red-700/80 dark:text-red-300/80">
                    These customers had 3 failed card attempts. Contact them to help finish the payment, or release the lock to let them try again.
                </p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-widest text-muted-foreground">
                        <th class="px-4 py-2 font-semibold">Ref</th>
                        <th class="px-4 py-2 font-semibold">Client</th>
                        <th class="px-4 py-2 font-semibold">Brand</th>
                        <th class="px-4 py-2 font-semibold">Provider</th>
                        <th class="px-4 py-2 text-right font-semibold">Amount</th>
                        <th class="px-4 py-2 font-semibold">Locked</th>
                        <th class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.uuid" class="border-t border-red-200/60 dark:border-red-900/40">
                        <td class="px-4 py-2 font-mono text-muted-foreground">
                            <Link :href="`/payments/${r.uuid}`" class="hover:underline">
                                {{ formatReferenceCode(r.reference_code) }}
                            </Link>
                        </td>
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ r.client_name ?? '—' }}</div>
                            <div v-if="r.rm_name" class="text-xs text-muted-foreground">{{ r.rm_name }}</div>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ r.brand_name ?? '—' }}</td>
                        <td class="px-4 py-2"><PaymentProviderBadge :provider="r.provider" /></td>
                        <td class="px-4 py-2 text-right font-mono">{{ formatMoney(r.amount, r.currency) }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-xs text-red-700 dark:text-red-300">{{ timeLeft(r.locked_until) }}</td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                variant="outline"
                                size="sm"
                                class="cursor-pointer"
                                :disabled="resetting === r.uuid"
                                @click="resetLock(r.uuid)"
                            >
                                <LockOpen class="size-3.5" />
                                Release lock
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
