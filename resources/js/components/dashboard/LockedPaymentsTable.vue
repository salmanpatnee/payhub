<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LockKeyhole } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatMoney, formatReferenceCode } from '@/lib/utils';
import type { LockedPaymentRow } from '@/types/dashboard';

defineProps<{
    rows: LockedPaymentRow[];
}>();

const PROVIDER_LABELS: Record<string, string> = {
    authorizenet: 'Authorize.net',
    mpgs: 'MPGS',
};

const resetting = ref<string | null>(null);

function minutesLeft(iso: string): number {
    return Math.max(1, Math.ceil((new Date(iso).getTime() - Date.now()) / 60_000));
}

function untilLabel(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
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
    <section class="overflow-hidden rounded-xl border border-border/70 bg-card shadow-sm" aria-labelledby="locked-payments-title">
        <header class="flex items-center gap-3 px-4 py-4 sm:px-5">
            <div class="flex size-[34px] shrink-0 items-center justify-center rounded-[10px] border border-red-200 bg-red-50 text-red-600 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-400">
                <LockKeyhole class="size-[17px]" />
            </div>
            <div class="min-w-0">
                <h3 id="locked-payments-title" class="flex items-center gap-2 text-base font-semibold tracking-tight">
                    Locked payments
                    <span class="rounded-full border border-red-200 bg-red-50 px-2 font-mono text-xs font-semibold text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300">
                        {{ rows.length }}
                    </span>
                </h3>
                <p class="text-[13px] text-muted-foreground">
                    Customers with 3 failed card attempts. Contact them, or release the lock.
                </p>
            </div>
        </header>

        <ul>
            <li
                v-for="r in rows"
                :key="r.uuid"
                class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-2 border-t border-border/60 px-4 py-3.5 transition-colors hover:bg-muted/40 sm:grid-cols-[1fr_auto_auto_auto] sm:px-5"
            >
                <div class="min-w-0">
                    <div class="truncate font-semibold">
                        <Link :href="`/payments/${r.uuid}`" class="mr-2 font-mono text-[12.5px] font-medium text-muted-foreground hover:text-foreground hover:underline">
                            {{ formatReferenceCode(r.reference_code) }}
                        </Link>
                        {{ r.client_name ?? '—' }}
                    </div>
                    <div class="truncate text-[12.5px] text-muted-foreground">
                        {{ r.brand_name ?? '—' }} · {{ PROVIDER_LABELS[r.provider] ?? r.provider }}
                    </div>
                </div>

                <div class="whitespace-nowrap text-right font-mono text-sm font-semibold tabular-nums sm:order-2">
                    {{ formatMoney(r.amount, r.currency) }}
                </div>

                <div class="col-span-2 whitespace-nowrap text-[13px] tabular-nums text-muted-foreground sm:order-3 sm:col-span-1">
                    <b class="font-semibold text-foreground">{{ minutesLeft(r.locked_until) }} min left</b>
                    · until {{ untilLabel(r.locked_until) }}
                </div>

                <div class="col-span-2 sm:order-4 sm:col-span-1">
                    <Button
                        variant="outline"
                        size="sm"
                        class="w-full cursor-pointer sm:w-auto"
                        :disabled="resetting === r.uuid"
                        @click="resetLock(r.uuid)"
                    >
                        Release lock
                    </Button>
                </div>
            </li>
        </ul>
    </section>
</template>
