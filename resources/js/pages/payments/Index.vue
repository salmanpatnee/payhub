<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useLocalStorage } from '@vueuse/core';
import { Check, Columns, Copy, Download, Eye, Filter, Pencil, Plus, RefreshCw, Search, Trash2, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import PaymentProviderBadge from '@/components/PaymentProviderBadge.vue';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { index as paymentsIndex } from '@/actions/App/Http/Controllers/PaymentController';
import { formatMoney, formatReferenceCode } from '@/lib/utils';

type PaymentRow = {
    id: number;
    uuid: string;
    reference_code: string | null;
    amount: number;
    currency: string;
    brand_name: string;
    provider: string;
    account_name: string | null;
    relationship_manager_name: string | null;
    status: string;
    created_at: string;
    client_email: string;
    client_name: string;
};

type FilterState = {
    brand_id: string;
    stripe_account_id: string;
    provider: string;
    relationship_manager_id: string;
    status: string;
    from: string;
    to: string;
    search: string;
};

type PaginatedPayments = {
    data: PaymentRow[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    from: number | null;
    to: number | null;
};

const props = defineProps<{
    payments: PaginatedPayments;
    filters: FilterState;
    brands: { id: number; name: string }[];
    accounts: { id: number; account_name: string }[];
    isAdmin: boolean;
    readOnly: boolean;
    canExport: boolean;
    canViewStripeAccount: boolean;
    relationshipManagers: { id: number; name: string }[];
}>();

const copiedUuid = ref<string | null>(null);

const deleteTarget = ref<PaymentRow | null>(null);
const deleteOpen   = ref(false);
const deleteForm   = useForm({});

function confirmDelete(payment: PaymentRow) {
    deleteTarget.value = payment;
    deleteOpen.value   = true;
}

function executeDelete() {
    if (!deleteTarget.value) return;
    deleteForm.delete(`/payments/${deleteTarget.value.uuid}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value   = false;
            deleteTarget.value = null;
        },
    });
}

const COLUMN_DEFS = [
    { key: 'reference_code', label: 'Reference' },
    { key: 'client', label: 'Client' },
    { key: 'amount', label: 'Amount' },
    { key: 'brand', label: 'Brand' },
    { key: 'provider', label: 'Provider' },
    { key: 'account_name', label: 'Account' },
    { key: 'relationship_manager_name', label: 'RM' },
    { key: 'status', label: 'Status' },
    { key: 'created', label: 'Created' },
] as const;

const visibleColumns = useLocalStorage<Record<string, boolean>>(
    'payments.columns',
    Object.fromEntries(COLUMN_DEFS.map((c) => [c.key, true])),
    { mergeDefaults: true },
);

// The # column is always visible; the Actions column only for non-read-only
// roles. The Provider and Account columns are excluded for roles that cannot
// view the Stripe account, so the skeleton/colspan count always matches the
// rendered columns.
const visibleColumnCount = computed(
    () =>
        1 +
        (props.readOnly ? 0 : 1) +
        COLUMN_DEFS.filter(
            (c) =>
                visibleColumns.value[c.key] &&
                ((c.key !== 'account_name' && c.key !== 'provider') || props.canViewStripeAccount),
        ).length,
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
        ],
    },
});

const UNSET = '__all';

const accountOptions = computed(() =>
    props.accounts.map((a) => ({ id: a.id, name: a.account_name })),
);

const filters = reactive<FilterState>({
    brand_id: props.filters.brand_id || UNSET,
    stripe_account_id: props.filters.stripe_account_id || UNSET,
    provider: props.filters.provider || UNSET,
    relationship_manager_id: props.filters.relationship_manager_id || UNSET,
    status: props.filters.status || UNSET,
    from: props.filters.from || '',
    to: props.filters.to || '',
    search: props.filters.search || '',
});

const hasActiveFilters = computed(() =>
    filters.brand_id !== UNSET ||
    filters.stripe_account_id !== UNSET ||
    filters.provider !== UNSET ||
    filters.relationship_manager_id !== UNSET ||
    filters.status !== UNSET ||
    filters.from !== '' ||
    filters.to !== '' ||
    filters.search !== ''
);

const activeFilterCount = computed(() =>
    [
        filters.brand_id !== UNSET,
        filters.stripe_account_id !== UNSET,
        filters.provider !== UNSET,
        filters.relationship_manager_id !== UNSET,
        filters.status !== UNSET,
        filters.from !== '',
        filters.to !== '',
        filters.search !== '',
    ].filter(Boolean).length
);

const pageItems = computed((): (number | '...')[] => {
    const current = props.payments.current_page;
    const last = props.payments.last_page;
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const items: (number | '...')[] = [1];
    if (current > 3) items.push('...');
    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);
    for (let i = start; i <= end; i++) items.push(i);
    if (current < last - 2) items.push('...');
    items.push(last);
    return items;
});

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

const exportUrl = computed((): string => {
    const activeFilters = Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== '' && v !== UNSET)
    ) as Record<string, string>;
    const qs = new URLSearchParams(activeFilters).toString();
    return qs ? `/payments/export?${qs}` : '/payments/export';
});

function clearFilters(): void {
    filters.brand_id = UNSET;
    filters.stripe_account_id = UNSET;
    filters.provider = UNSET;
    filters.relationship_manager_id = UNSET;
    filters.status = UNSET;
    filters.from = '';
    filters.to = '';
    filters.search = '';
}

const refreshing = ref(false);

const skeletonRowCount = computed(() =>
    Math.min(Math.max(props.payments.data.length, 1), 10),
);

function refresh(): void {
    refreshing.value = true;
    router.reload({
        onFinish: () => { refreshing.value = false; },
    });
}

function goToPage(page: number): void {
    const activeFilters = Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== '' && v !== UNSET)
    );
    router.get(
        paymentsIndex.url({ query: { ...activeFilters, page } }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const relativeTime = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

function formatDate(iso: string): string {
    const d = new Date(iso);
    const diffMs = Date.now() - d.getTime();
    const diffSec = Math.round(diffMs / 1000);

    if (diffSec < 60) return 'just now';

    const diffMin = Math.round(diffSec / 60);
    if (diffMin < 60) return relativeTime.format(-diffMin, 'minute');

    const diffHour = Math.round(diffMin / 60);
    if (diffHour < 24) return relativeTime.format(-diffHour, 'hour');

    const month = d.toLocaleDateString('en-GB', { month: 'short' });
    return `${d.getDate()}-${month}-${d.getFullYear()}`;
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
            <h1 class="text-2xl font-semibold tracking-tight">Payments</h1>
            <div class="flex items-center gap-2">
                <Button v-if="canExport" as-child variant="outline">
                    <a :href="exportUrl">
                        <Download class="size-4 mr-1" />
                        Export
                    </a>
                </Button>
                <Button v-if="!readOnly" as-child>
                    <Link href="/payments/create">
                        <Plus class="size-4 mr-1" />
                        New payment
                    </Link>
                </Button>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-border/60 bg-[#F7F5F2]">
                <div class="flex items-center gap-2">
                    <Filter class="size-3.5 text-muted-foreground" />
                    <span class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Filters</span>
                    <span
                        v-if="hasActiveFilters"
                        class="inline-flex items-center justify-center size-4 rounded-full bg-foreground text-background text-[10px] font-bold leading-none"
                    >{{ activeFilterCount }}</span>
                </div>
                <Button
                    v-if="hasActiveFilters"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs gap-1"
                    aria-label="Clear all filters"
                    @click="clearFilters"
                >
                    <X class="size-3" />
                    Clear all
                </Button>
            </div>

            <div class="flex gap-4 p-4">
                <div class="flex flex-col gap-1.5 flex-[2] min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Search</Label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            v-model="filters.search"
                            type="search"
                            placeholder="Name, email or reference…"
                            class="pl-8"
                        />
                    </div>
                </div>

                <div v-if="brands.length" class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Brand</Label>
                    <SearchableSelect
                        v-model="filters.brand_id"
                        :options="brands"
                        :all-value="UNSET"
                        all-label="All Brands"
                        placeholder="All brands"
                        search-placeholder="Search brands…"
                    />
                </div>

                <div v-if="accounts.length" class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Account</Label>
                    <SearchableSelect
                        v-model="filters.stripe_account_id"
                        :options="accountOptions"
                        :all-value="UNSET"
                        all-label="All Accounts"
                        placeholder="All accounts"
                        search-placeholder="Search accounts…"
                    />
                </div>

                <div v-if="canViewStripeAccount" class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Provider</Label>
                    <Select v-model="filters.provider">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All providers" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all">All Providers</SelectItem>
                            <SelectItem value="stripe">Stripe</SelectItem>
                            <SelectItem value="revolut">Revolut</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">RM</Label>
                    <SearchableSelect
                        v-model="filters.relationship_manager_id"
                        :options="relationshipManagers"
                        :all-value="UNSET"
                        all-label="All RMs"
                        placeholder="All RMs"
                        search-placeholder="Search RMs…"
                    />
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</Label>
                    <Select v-model="filters.status">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all">All Statuses</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="failed">Failed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">From</Label>
                    <Input v-model="filters.from" type="date" class="w-full" />
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">To</Label>
                    <Input v-model="filters.to" type="date" class="w-full" />
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-border/60 bg-[#F7F5F2]">
                <span class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">{{ payments.total }} payments</span>
                <div class="flex items-center gap-1">
                    <DropdownMenu v-if="isAdmin">
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="sm" class="h-7 px-2 text-xs gap-1">
                                <Columns class="size-3.5" />
                                Columns
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>Toggle columns</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuCheckboxItem
                                v-for="col in COLUMN_DEFS"
                                :key="col.key"
                                :model-value="visibleColumns[col.key]"
                                @update:model-value="(v: boolean) => (visibleColumns[col.key] = v)"
                                @select.prevent
                            >
                                {{ col.label }}
                            </DropdownMenuCheckboxItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-7 w-7 p-0"
                        :disabled="refreshing"
                        title="Refresh payments"
                        aria-label="Refresh payments"
                        @click="refresh"
                    >
                        <RefreshCw :class="['size-3.5', refreshing && 'animate-spin']" />
                    </Button>
                </div>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-[#F7F5F2] border-b border-border">
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">#</th>
                        <th v-if="visibleColumns.reference_code" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Reference</th>
                        <th v-if="visibleColumns.client" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Client</th>
                        <th v-if="visibleColumns.amount" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Amount</th>
                        <th v-if="visibleColumns.brand" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Brand</th>
                        <th v-if="canViewStripeAccount && visibleColumns.provider" class="text-center px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Provider</th>
                        <th v-if="canViewStripeAccount && visibleColumns.account_name" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Account</th>
                        <th v-if="visibleColumns.relationship_manager_name" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">RM</th>
                        <th v-if="visibleColumns.status" class="text-center px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</th>
                        <th v-if="visibleColumns.created" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Created</th>
                        <th v-if="!readOnly" class="text-center px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody v-if="refreshing">
                    <tr
                        v-for="n in skeletonRowCount"
                        :key="`skeleton-${n}`"
                        class="border-b border-border/50 last:border-0"
                    >
                        <td v-for="c in visibleColumnCount" :key="c" class="px-5 py-3.5">
                            <div class="h-4 rounded bg-muted animate-pulse" :style="{ width: `${40 + ((c * 13) % 45)}%` }"></div>
                        </td>
                    </tr>
                </tbody>
                <tbody v-else>
                    <tr
                        v-for="(payment, index) in payments.data"
                        :key="payment.id"
                        class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                    >
                        <td class="px-5 py-3.5 text-muted-foreground tabular-nums">{{ (payments.from ?? 1) + index }}</td>
                        <td v-if="visibleColumns.reference_code" class="px-5 py-3.5 font-mono text-muted-foreground">{{ formatReferenceCode(payment.reference_code) }}</td>
                        <td v-if="visibleColumns.client" class="px-5 py-3.5 font-medium">{{ payment.client_name }}</td>
                        <td v-if="visibleColumns.amount" class="px-5 py-3.5 font-mono">{{ formatMoney(payment.amount, payment.currency) }}</td>
                        <td v-if="visibleColumns.brand" class="px-5 py-3.5">{{ payment.brand_name }}</td>
                        <td v-if="canViewStripeAccount && visibleColumns.provider" class="px-5 py-3.5">
                            <div class="flex justify-center">
                                <PaymentProviderBadge :provider="payment.provider" />
                            </div>
                        </td>
                        <td v-if="canViewStripeAccount && visibleColumns.account_name" class="px-5 py-3.5">{{ payment.account_name ?? '—' }}</td>
                        <td v-if="visibleColumns.relationship_manager_name" class="px-5 py-3.5">{{ payment.relationship_manager_name ?? '—' }}</td>
                        <td v-if="visibleColumns.status" class="px-5 py-3.5">
                            <div class="flex justify-center">
                                <PaymentStatusBadge :status="payment.status" icon-only />
                            </div>
                        </td>
                        <td v-if="visibleColumns.created" class="px-5 py-3.5 text-muted-foreground">
                            {{ formatDate(payment.created_at) }}
                        </td>
                        <td v-if="!readOnly" class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-0.5">
                                <Button v-if="!readOnly" variant="ghost" size="icon-sm" as-child title="View payment details">
                                    <Link :href="`/payments/${payment.uuid}`">
                                        <Eye class="size-3.5" />
                                    </Link>
                                </Button>
                                <Button v-if="!readOnly" variant="ghost" size="icon-sm" title="Copy payment link" @click="copyLink(payment.uuid)">
                                    <Check v-if="copiedUuid === payment.uuid" class="size-3.5 text-green-600" />
                                    <Copy v-else class="size-3.5" />
                                </Button>
                                <Button
                                    v-if="!readOnly && payment.status === 'pending'"
                                    variant="ghost"
                                    size="icon-sm"
                                    as-child
                                    title="Edit payment"
                                >
                                    <Link :href="`/payments/${payment.uuid}/edit`">
                                        <Pencil class="size-3.5" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="isAdmin"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="cursor-pointer"
                                    title="Delete payment"
                                    :disabled="deleteForm.processing && deleteTarget?.uuid === payment.uuid"
                                    @click="confirmDelete(payment)"
                                >
                                    <Trash2 class="size-3.5 text-destructive" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="payments.data.length === 0">
                        <td :colspan="visibleColumnCount" class="px-5 py-16 text-center text-muted-foreground text-sm">
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

            <div v-if="payments.last_page > 1" class="flex items-center justify-center border-t border-border/50 px-5 py-3.5">
                <!-- Centered page navigation -->
                <nav class="flex items-center gap-0.5" aria-label="Pagination">
                    <!-- First & Prev -->
                    <button
                        :disabled="payments.current_page === 1"
                        class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                        title="First page"
                        @click="goToPage(1)"
                    >«</button>
                    <button
                        :disabled="payments.current_page === 1"
                        class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                        title="Previous page"
                        @click="goToPage(payments.current_page - 1)"
                    >‹</button>

                    <!-- Numbered pages + ellipsis -->
                    <div class="mx-1 flex items-center gap-0.5">
                        <template v-for="(item, i) in pageItems" :key="i">
                            <span
                                v-if="item === '...'"
                                class="flex h-8 w-6 select-none items-end justify-center pb-1 text-[10px] tracking-widest text-muted-foreground/40"
                            >···</span>
                            <button
                                v-else
                                :class="[
                                    'relative flex h-8 w-8 items-center justify-center rounded text-xs font-medium tabular-nums transition-all duration-150',
                                    item === payments.current_page
                                        ? 'bg-primary text-primary-foreground shadow-sm scale-105'
                                        : 'text-foreground/60 hover:bg-muted hover:text-foreground',
                                ]"
                                @click="goToPage(item as number)"
                            >{{ item }}</button>
                        </template>
                    </div>

                    <!-- Next & Last -->
                    <button
                        :disabled="payments.current_page === payments.last_page"
                        class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                        title="Next page"
                        @click="goToPage(payments.current_page + 1)"
                    >›</button>
                    <button
                        :disabled="payments.current_page === payments.last_page"
                        class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                        title="Last page"
                        @click="goToPage(payments.last_page)"
                    >»</button>
                </nav>
            </div>
        </div>
    </div>

    <ConfirmDeleteDialog
        v-model:open="deleteOpen"
        :title="`Delete payment ${deleteTarget?.reference_code != null ? formatReferenceCode(deleteTarget.reference_code) : ''}?`"
        description="This will remove the payment from the list. The payment link will no longer be accessible."
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
