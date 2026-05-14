<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { Check, Copy, Eye, Plus, X } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as paymentsIndex } from '@/actions/App/Http/Controllers/PaymentController';

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

type FilterState = {
    brand_id: string;
    stripe_account_id: string;
    status: string;
    from: string;
    to: string;
};

const props = defineProps<{
    payments: PaymentRow[];
    filters: FilterState;
    brands: { id: number; name: string }[];
    accounts: { id: number; account_name: string }[];
    isAdmin: boolean;
}>();

const copiedUuid = ref<string | null>(null);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
        ],
    },
});

const UNSET = '__all';

const filters = reactive<FilterState>({
    brand_id: props.filters.brand_id || UNSET,
    stripe_account_id: props.filters.stripe_account_id || UNSET,
    status: props.filters.status || UNSET,
    from: props.filters.from || '',
    to: props.filters.to || '',
});

const hasActiveFilters = computed(() =>
    filters.brand_id !== UNSET ||
    filters.stripe_account_id !== UNSET ||
    filters.status !== UNSET ||
    filters.from !== '' ||
    filters.to !== ''
);

watch(
    filters,
    (newFilters) => {
        const activeFilters = Object.fromEntries(
            Object.entries(newFilters).filter(([, v]) => v !== '' && v !== UNSET)
        );
        router.get(
            paymentsIndex.url({ query: activeFilters }),
            {},
            { preserveState: true, replace: true },
        );
    },
    { deep: true },
);

function clearFilters(): void {
    filters.brand_id = UNSET;
    filters.stripe_account_id = UNSET;
    filters.status = UNSET;
    filters.from = '';
    filters.to = '';
}

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

        <!-- Filter bar — appears between page header and table card -->
        <div class="flex items-center gap-3 flex-wrap mb-4">
            <!-- Brand filter — admin only (D-05) -->
            <div v-if="isAdmin" class="flex flex-col gap-1">
                <Label class="text-xs text-muted-foreground">Brand</Label>
                <Select v-model="filters.brand_id">
                    <SelectTrigger class="w-40">
                        <SelectValue placeholder="All brands" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all">All brands</SelectItem>
                        <SelectItem
                            v-for="brand in brands"
                            :key="brand.id"
                            :value="String(brand.id)"
                        >
                            {{ brand.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Stripe account filter — admin only (D-05) -->
            <div v-if="isAdmin" class="flex flex-col gap-1">
                <Label class="text-xs text-muted-foreground">Account</Label>
                <Select v-model="filters.stripe_account_id">
                    <SelectTrigger class="w-44">
                        <SelectValue placeholder="All accounts" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all">All accounts</SelectItem>
                        <SelectItem
                            v-for="account in accounts"
                            :key="account.id"
                            :value="String(account.id)"
                        >
                            {{ account.account_name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Status filter — all roles -->
            <div class="flex flex-col gap-1">
                <Label class="text-xs text-muted-foreground">Status</Label>
                <Select v-model="filters.status">
                    <SelectTrigger class="w-36">
                        <SelectValue placeholder="All statuses" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all">All statuses</SelectItem>
                        <SelectItem value="pending">Pending</SelectItem>
                        <SelectItem value="completed">Completed</SelectItem>
                        <SelectItem value="failed">Failed</SelectItem>
                        <SelectItem value="cancelled">Cancelled</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- From date — all roles -->
            <div class="flex flex-col gap-1">
                <Label class="text-xs text-muted-foreground">From</Label>
                <Input v-model="filters.from" type="date" class="w-36" />
            </div>

            <!-- To date — all roles -->
            <div class="flex flex-col gap-1">
                <Label class="text-xs text-muted-foreground">To</Label>
                <Input v-model="filters.to" type="date" class="w-36" />
            </div>

            <!-- Clear filters — visible only when any filter active -->
            <div v-if="hasActiveFilters" class="flex flex-col justify-end">
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label="Clear all filters"
                    @click="clearFilters"
                >
                    <X class="size-4 mr-1" />
                    Clear filters
                </Button>
            </div>
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
                            <template v-if="hasActiveFilters">
                                No payments match your filters.
                                <button class="underline" @click="clearFilters">Clear filters to see all.</button>
                            </template>
                            <template v-else>
                                No payments yet. <Link href="/payments/create" class="underline">Create one.</Link>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
