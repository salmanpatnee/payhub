<script setup lang="ts">
import { ChevronDown, ChevronRight, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PaymentProviderBadge from '@/components/PaymentProviderBadge.vue';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import { Card } from '@/components/ui/card';

export type PaymentAttemptRow = {
    id: number;
    sequence: number;
    event: string;
    status: 'succeeded' | 'failed' | 'cancelled' | 'pending' | string;
    amount: number | null;
    currency: string | null;
    amount_reported: boolean;
    reference: string | null;
    error_code: string | null;
    reason: string | null;
    source: string;
    request: Record<string, unknown> | null;
    response: Record<string, unknown> | null;
    occurred_at: string;
};

export type PaymentAttemptLogData = { provider: string; rows: PaymentAttemptRow[] };

const props = defineProps<{ log: PaymentAttemptLogData }>();

const labelClass = 'text-[11px] font-semibold uppercase tracking-widest text-muted-foreground';
const expanded = ref<Set<number>>(new Set());
const copiedId = ref<number | null>(null);

// The shared badge speaks payment statuses; map the attempt words onto its tones.
const badgeStatus = (status: string): string => (status === 'succeeded' ? 'completed' : status);
const statusLabel = (status: string): string => status.charAt(0).toUpperCase() + status.slice(1);

function formatAmount(cents: number | null, currency: string | null): string {
    if (cents === null || !currency) return '—';
    try {
        return new Intl.NumberFormat(
            currency === 'gbp' ? 'en-GB' : currency === 'pkr' ? 'en-PK' : 'en-US',
            { style: 'currency', currency: currency.toUpperCase() },
        ).format(cents / 100);
    } catch {
        return '—';
    }
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

const dash = (value: string | null): string => (value && value !== '' ? value : '—');

function hasDetails(row: PaymentAttemptRow): boolean {
    return Boolean((row.request && Object.keys(row.request).length) || (row.response && Object.keys(row.response).length));
}

/** Flattens nested details to key and value lines. Values are plain text, never HTML. */
function flatten(data: Record<string, unknown> | null, prefix = ''): { key: string; value: string }[] {
    if (!data) return [];
    const out: { key: string; value: string }[] = [];
    for (const [k, v] of Object.entries(data)) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v !== null && typeof v === 'object') {
            out.push(...flatten(v as Record<string, unknown>, key));
        } else {
            out.push({ key, value: v === null ? '—' : String(v) });
        }
    }
    return out;
}

function toggle(id: number): void {
    const next = new Set(expanded.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    expanded.value = next;
}

async function copyDetails(row: PaymentAttemptRow): Promise<void> {
    const text = JSON.stringify({ request: row.request, response: row.response }, null, 2);
    try {
        await navigator.clipboard.writeText(text);
        copiedId.value = row.id;
        setTimeout(() => { copiedId.value = null; }, 2000);
    } catch {
        // Clipboard may be blocked; nothing to do.
    }
}

const rows = computed(() => props.log.rows);
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0">
        <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold">Payment attempts</h2>
                <PaymentProviderBadge :provider="log.provider" show-name />
            </div>
            <span class="rounded-md bg-muted px-2 py-1 text-xs font-semibold text-muted-foreground">Admins only</span>
        </div>

        <p v-if="!rows.length" class="px-5 py-5 text-sm text-muted-foreground sm:px-6">
            No attempts recorded for this payment. History starts from the day this feature went live.
        </p>

        <template v-else>
            <!-- Wide screens -->
            <table class="hidden w-full text-sm sm:table">
                <thead>
                    <tr class="border-b border-border text-left" :class="labelClass">
                        <th class="w-10 px-4 py-3"><span class="sr-only">Details</span></th>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Amount</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Reference</th>
                        <th class="px-4 py-3">Error code</th>
                        <th class="px-4 py-3">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row.id">
                        <tr class="border-b border-border last:border-0">
                            <td class="px-4 py-3.5 align-top">
                                <button
                                    v-if="hasDetails(row)"
                                    type="button"
                                    class="rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline focus-visible:outline-2"
                                    :aria-expanded="expanded.has(row.id)"
                                    :aria-controls="`attempt-details-${row.id}`"
                                    :aria-label="expanded.has(row.id) ? 'Hide details' : 'Show details'"
                                    @click="toggle(row.id)"
                                >
                                    <ChevronDown v-if="expanded.has(row.id)" class="size-4" />
                                    <ChevronRight v-else class="size-4" />
                                </button>
                            </td>
                            <td class="px-4 py-3.5 align-top tabular-nums">{{ row.sequence }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5 align-top tabular-nums">{{ formatDateTime(row.occurred_at) }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5 align-top tabular-nums">{{ formatAmount(row.amount, row.currency) }}</td>
                            <td class="px-4 py-3.5 align-top"><PaymentStatusBadge :status="badgeStatus(row.status)" :label="statusLabel(row.status)" /></td>
                            <td class="break-all px-4 py-3.5 align-top font-mono text-xs">{{ dash(row.reference) }}</td>
                            <td class="px-4 py-3.5 align-top font-mono text-xs">{{ dash(row.error_code) }}</td>
                            <td class="px-4 py-3.5 align-top">{{ dash(row.reason) }}</td>
                        </tr>
                        <tr v-if="expanded.has(row.id)" :id="`attempt-details-${row.id}`" class="border-b border-border bg-muted/30 last:border-0">
                            <td colspan="8" class="px-6 py-4">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <p class="text-xs text-muted-foreground">Event: <span class="font-mono">{{ row.event }}</span></p>
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted" @click="copyDetails(row)">
                                        <Copy class="size-3.5" />
                                        {{ copiedId === row.id ? 'Copied!' : 'Copy' }}
                                    </button>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div v-for="section in [{ title: 'Request', data: row.request }, { title: 'Response', data: row.response }]" :key="section.title">
                                        <template v-if="section.data && Object.keys(section.data).length">
                                            <p :class="labelClass">{{ section.title }}</p>
                                            <dl class="mt-2 space-y-1 text-xs">
                                                <div v-for="item in flatten(section.data)" :key="item.key" class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-2">
                                                    <dt class="break-all text-muted-foreground">{{ item.key }}</dt>
                                                    <dd class="break-all font-mono">{{ item.value }}</dd>
                                                </div>
                                            </dl>
                                        </template>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <!-- Phone -->
            <ul class="divide-y divide-border sm:hidden">
                <li v-for="row in rows" :key="`m-${row.id}`" class="space-y-2 px-4 py-3.5">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold">Attempt {{ row.sequence }}</p>
                        <PaymentStatusBadge :status="badgeStatus(row.status)" :label="statusLabel(row.status)" />
                    </div>
                    <p class="text-xs tabular-nums text-muted-foreground">{{ formatDateTime(row.occurred_at) }}</p>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <div>
                            <dt :class="labelClass">Amount</dt>
                            <dd class="mt-0.5 tabular-nums">{{ formatAmount(row.amount, row.currency) }}</dd>
                        </div>
                        <div>
                            <dt :class="labelClass">Error code</dt>
                            <dd class="mt-0.5 font-mono text-xs">{{ dash(row.error_code) }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt :class="labelClass">Reference</dt>
                            <dd class="mt-0.5 break-all font-mono text-xs">{{ dash(row.reference) }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt :class="labelClass">Reason</dt>
                            <dd class="mt-0.5">{{ dash(row.reason) }}</dd>
                        </div>
                    </dl>
                    <template v-if="hasDetails(row)">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                            :aria-expanded="expanded.has(row.id)"
                            @click="toggle(row.id)"
                        >
                            <ChevronDown v-if="expanded.has(row.id)" class="size-4" />
                            <ChevronRight v-else class="size-4" />
                            {{ expanded.has(row.id) ? 'Hide details' : 'Show details' }}
                        </button>
                        <div v-if="expanded.has(row.id)" class="space-y-3 rounded-lg bg-muted/30 p-3">
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs font-medium" @click="copyDetails(row)">
                                <Copy class="size-3.5" />
                                {{ copiedId === row.id ? 'Copied!' : 'Copy' }}
                            </button>
                            <div v-for="section in [{ title: 'Request', data: row.request }, { title: 'Response', data: row.response }]" :key="section.title">
                                <template v-if="section.data && Object.keys(section.data).length">
                                    <p :class="labelClass">{{ section.title }}</p>
                                    <dl class="mt-1 space-y-1 text-xs">
                                        <div v-for="item in flatten(section.data)" :key="item.key">
                                            <dt class="break-all text-muted-foreground">{{ item.key }}</dt>
                                            <dd class="break-all font-mono">{{ item.value }}</dd>
                                        </div>
                                    </dl>
                                </template>
                            </div>
                        </div>
                    </template>
                </li>
            </ul>
        </template>
    </Card>
</template>
