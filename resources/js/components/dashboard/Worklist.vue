<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import { formatMoney, formatReferenceCode } from '@/lib/utils';
import type { WorklistRow } from '@/types/dashboard';

defineProps<{
    title: string;
    subtitle?: string;
    rows: WorklistRow[];
    /** Show the expiry column (stale-pending list). */
    showExpiry?: boolean;
}>();

function expiryLabel(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    const now = Date.now();
    const diffDays = Math.round((d.getTime() - now) / 86_400_000);
    if (diffDays < 0) return `expired ${Math.abs(diffDays)}d ago`;
    if (diffDays === 0) return 'expires today';
    return `in ${diffDays}d`;
}
</script>

<template>
    <div class="rounded-xl border border-border/70 bg-card shadow-sm">
        <div class="border-b border-border/60 px-4 py-3">
            <h3 class="text-sm font-semibold">{{ title }}</h3>
            <p v-if="subtitle" class="text-xs text-muted-foreground">{{ subtitle }}</p>
        </div>
        <div v-if="rows.length === 0" class="px-4 py-8 text-center text-sm text-muted-foreground">
            Nothing here.
        </div>
        <table v-else class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-widest text-muted-foreground">
                    <th class="px-4 py-2 font-semibold">Ref</th>
                    <th class="px-4 py-2 font-semibold">Client</th>
                    <th class="px-4 py-2 font-semibold">Brand</th>
                    <th class="px-4 py-2 text-right font-semibold">Amount</th>
                    <th v-if="showExpiry" class="px-4 py-2 text-right font-semibold">Expiry</th>
                    <th v-else class="px-4 py-2 font-semibold">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.uuid" class="border-t border-border/50 hover:bg-muted/40">
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
                    <td class="px-4 py-2 text-right font-mono">{{ formatMoney(r.amount, r.currency) }}</td>
                    <td v-if="showExpiry" class="px-4 py-2 text-right text-xs text-muted-foreground">
                        {{ expiryLabel(r.expires_at) }}
                    </td>
                    <td v-else class="px-4 py-2">
                        <PaymentStatusBadge :status="r.status" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
