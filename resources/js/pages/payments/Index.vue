<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Check, Copy, Eye, Plus } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type PaymentRow = {
    id: number;
    uuid: string;
    amount: number;
    currency: string;
    brand_name: string;
    account_name: string;
    status: string;
    created_at: string;
    client_email: string;
    client_name: string;
};

defineProps<{ payments: PaymentRow[] }>();

const copiedUuid = ref<string | null>(null);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
        ],
    },
});

function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100);
}

function statusClass(status: string): string {
    if (status === 'completed')  return 'border-green-500 bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400';
    if (status === 'pending')    return 'border-amber-400 bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400';
    if (status === 'failed')     return 'border-red-500 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400';
    if (status === 'cancelled')  return 'border-gray-400 bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-400';
    return '';
}

async function copyLink(uuid: string): Promise<void> {
    const url = `${window.location.origin}/pay/${uuid}`;
    try {
        await navigator.clipboard.writeText(url);
    } catch {
        const el = document.createElement('textarea');
        el.value = url;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    copiedUuid.value = uuid;
    setTimeout(() => { copiedUuid.value = null; }, 2000);
}
</script>

<template>
    <Head title="Payments" />

    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Payments</h1>
            <Button as-child>
                <Link href="/payments/create">
                    <Plus class="size-4 mr-1" />
                    New payment
                </Link>
            </Button>
        </div>

        <div class="rounded-lg border border-border bg-card overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-muted/40 border-b border-border">
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Client</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Email</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Amount</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Brand</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Account</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Created</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="payment in payments"
                        :key="payment.id"
                        class="border-b border-border last:border-0 hover:bg-muted/50 transition-colors"
                    >
                        <td class="px-4 py-3 font-medium">{{ payment.client_name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ payment.client_email }}</td>
                        <td class="px-4 py-3 font-mono">{{ formatAmount(payment.amount, payment.currency) }}</td>
                        <td class="px-4 py-3">{{ payment.brand_name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ payment.account_name }}</td>
                        <td class="px-4 py-3">
                            <Badge variant="outline" :class="statusClass(payment.status)">
                                {{ payment.status }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ new Date(payment.created_at).toLocaleDateString() }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button variant="ghost" size="sm" as-child title="View payment details">
                                    <Link :href="`/payments/${payment.uuid}`">
                                        <Eye class="size-4" />
                                    </Link>
                                </Button>
                                <Button variant="ghost" size="sm" title="Copy payment link" @click="copyLink(payment.uuid)">
                                    <Check v-if="copiedUuid === payment.uuid" class="size-4 text-green-600" />
                                    <Copy v-else class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="payments.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center text-muted-foreground text-sm">
                            No payments yet. <Link href="/payments/create" class="underline">Create one.</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
