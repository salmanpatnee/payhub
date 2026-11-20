<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, provide, reactive, ref, watch } from 'vue';
import { useLocalStorage, useMediaQuery } from '@vueuse/core';
import { Check, Columns, Copy, Download, Eye, Filter, LockKeyhole, LockOpen, Pencil, Plus, RefreshCw, Search, Trash2, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import PaymentProviderBadge from '@/components/PaymentProviderBadge.vue';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import PaymentFilters from '@/components/payments/PaymentFilters.vue';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { index as paymentsIndex } from '@/actions/App/Http/Controllers/PaymentController';
import { formatMoney } from '@/lib/utils';
import { withBase } from '@/lib/basePath';

type PaymentRow = {
    id: number;
    uuid: string;
    reference_code: string | null;
    reference_slug: string | null;
    amount: number;
    currency: string;
    brand_name: string;
    provider: string;
    country_name: string | null;
    relationship_manager_name: string | null;
    status: string;
    created_at: string;
    client_email: string;
    client_name: string;
    /** Admin only: when the card attempt lock ends, or null when the payment is not locked. */
    card_locked_until: string | null;
};

type FilterState = {
    brand_id: string;
    country: string;
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
    countries: { id: string; name: string }[];
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

const resetting = ref<string | null>(null);

function resetLock(payment: PaymentRow) {
    resetting.value = payment.uuid;
    router.patch(`/payments/${payment.uuid}/reset-lock`, {}, {
        preserveScroll: true,
        onFinish: () => {
            resetting.value = null;
        },
    });
}

function lockedUntilLabel(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

const COLUMN_DEFS = [
    { key: 'reference_code', label: 'Reference' },
    { key: 'client', label: 'Client' },
    { key: 'amount', label: 'Amount' },
    { key: 'brand', label: 'Brand' },
    { key: 'provider', label: 'Provider' },
    { key: 'country_name', label: 'Country' },
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
                (c.key !== 'provider' || props.canViewStripeAccount),
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

// Phones get cards and a filters sheet; tablets and up get the table.
const isPhone = useMediaQuery('(max-width: 767px)');
const filtersOpen = ref(false);
// Searchable selects inside the filters sheet render their list inside the
// sheet; the modal sheet blocks input to anything portaled to <body>.
const sheetPortalEl = ref<HTMLElement | null>(null);
provide('popover-portal-target', sheetPortalEl);

const filters = reactive<FilterState>({
    brand_id: props.filters.brand_id || UNSET,
    country: props.filters.country || UNSET,
    provider: props.filters.provider || UNSET,
    relationship_manager_id: props.filters.relationship_manager_id || UNSET,
    status: props.filters.status || UNSET,
    from: props.filters.from || '',
    to: props.filters.to || '',
    search: props.filters.search || '',
});

const hasActiveFilters = computed(() =>
    filters.brand_id !== UNSET ||
    filters.country !== UNSET ||
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
        filters.country !== UNSET,
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
    filters.country = UNSET;
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

async function copyLink(uuid: string, referenceSlug: string | null): Promise<void> {
    const url = `${window.location.origin}${withBase(`/pay/${uuid}${referenceSlug ? `/${referenceSlug}` : ''}`)}`;
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

    <div class="space-y-4 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">Payments</h1>
            <div class="flex items-center gap-2">
                <Button v-if="canExport" as-child variant="outline" class="max-sm:size-9 max-sm:px-0" aria-label="Export payments">
                    <a :href="exportUrl">
                        <Download class="size-4 sm:mr-1" />
                        <span class="max-sm:sr-only">Export</span>
                    </a>
                </Button>
                <Button v-if="!readOnly" as-child>
                    <Link href="/payments/create">
                        <Plus class="mr-1 size-4" />
                        New payment
                    </Link>
                </Button>
            </div>
        </div>

        <!-- Phone: search plus a Filters button that opens a sheet -->
        <div v-if="isPhone" class="flex items-center gap-2">
            <div class="relative min-w-0 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    v-model="filters.search"
                    type="search"
                    placeholder="Name, email, reference or amount…"
                    class="h-10 pl-8"
                    aria-label="Search payments"
                />
            </div>
            <Button
                variant="outline"
                class="relative size-10 shrink-0 px-0"
                aria-label="Open filters"
                @click="filtersOpen = true"
            >
                <Filter class="size-4" />
                <span
                    v-if="hasActiveFilters"
                    class="absolute -right-1.5 -top-1.5 inline-flex size-4 items-center justify-center rounded-full bg-foreground text-[10px] font-bold leading-none text-background"
                >{{ activeFilterCount }}</span>
            </Button>
        </div>

        <Sheet v-if="isPhone" v-model:open="filtersOpen">
            <SheetContent side="bottom" class="max-h-[85dvh] overflow-y-auto rounded-t-2xl">
                <SheetHeader>
                    <SheetTitle>Filters</SheetTitle>
                    <SheetDescription class="sr-only">Filter the payments list</SheetDescription>
                </SheetHeader>
                <div ref="sheetPortalEl" class="grid grid-cols-2 gap-3 px-4">
                    <PaymentFilters
                        v-model:filters="filters"
                        :brands="brands"
                        :countries="countries"
                        :relationship-managers="relationshipManagers"
                        :can-view-stripe-account="canViewStripeAccount"
                    />
                </div>
                <SheetFooter class="flex-row gap-2">
                    <Button variant="outline" class="h-11 flex-1" :disabled="!hasActiveFilters" @click="clearFilters">
                        Clear all
                    </Button>
                    <Button class="h-11 flex-[2]" @click="filtersOpen = false">
                        Show {{ payments.total }} payments
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>

        <!-- Tablet and up: filter panel -->
        <div v-else class="overflow-hidden rounded-xl border border-border/70 bg-card shadow-sm">
            <div class="flex items-center justify-between border-b border-border/60 bg-[#F7F5F2] px-5 py-3">
                <div class="flex items-center gap-2">
                    <Filter class="size-3.5 text-muted-foreground" />
                    <span class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Filters</span>
                    <span
                        v-if="hasActiveFilters"
                        class="inline-flex size-4 items-center justify-center rounded-full bg-foreground text-[10px] font-bold leading-none text-background"
                    >{{ activeFilterCount }}</span>
                </div>
                <Button
                    v-if="hasActiveFilters"
                    variant="ghost"
                    size="sm"
                    class="h-7 gap-1 px-2 text-xs"
                    aria-label="Clear all filters"
                    @click="clearFilters"
                >
                    <X class="size-3" />
                    Clear all
                </Button>
            </div>

            <div class="grid grid-cols-2 gap-4 p-4 lg:grid-cols-4 2xl:grid-cols-8">
                <PaymentFilters
                    show-search
                    v-model:filters="filters"
                    :brands="brands"
                    :countries="countries"
                    :relationship-managers="relationshipManagers"
                    :can-view-stripe-account="canViewStripeAccount"
                />
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-border/70 bg-card shadow-sm">
            <div class="flex items-center justify-between border-b border-border/60 bg-[#F7F5F2] px-4 py-3 md:px-5">
                <span class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">{{ payments.total }} payments</span>
                <div class="flex items-center gap-1">
                    <DropdownMenu v-if="isAdmin && !isPhone">
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="sm" class="h-7 gap-1 px-2 text-xs">
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

            <!-- Phone: one card per payment -->
            <div v-if="isPhone">
                <div v-if="refreshing" class="space-y-3 bg-muted/40 p-3">
                    <div v-for="n in skeletonRowCount" :key="`card-skeleton-${n}`" class="space-y-3 rounded-xl border bg-card p-3.5">
                        <div class="flex justify-between gap-4">
                            <div class="h-4 w-1/2 animate-pulse rounded bg-muted"></div>
                            <div class="h-4 w-1/4 animate-pulse rounded bg-muted"></div>
                        </div>
                        <div class="flex justify-between gap-4">
                            <div class="h-3 w-1/4 animate-pulse rounded bg-muted"></div>
                            <div class="h-4 w-1/5 animate-pulse rounded-full bg-muted"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-2.5 border-t border-dashed pt-2.5">
                            <div v-for="m in 4" :key="m" class="h-7 animate-pulse rounded bg-muted"></div>
                        </div>
                    </div>
                </div>
                <div v-else-if="payments.data.length" class="space-y-3 bg-muted/40 p-3">
                    <div
                        v-for="payment in payments.data"
                        :key="payment.id"
                        class="overflow-hidden rounded-xl border bg-card"
                    >
                        <div class="space-y-2.5 p-3.5">
                            <div class="flex items-start justify-between gap-3">
                                <span class="line-clamp-2 min-w-0 break-words text-[15px] font-semibold leading-snug">{{ payment.client_name }}</span>
                                <span class="shrink-0 font-mono text-[17px] font-semibold tabular-nums">{{ formatMoney(payment.amount, payment.currency, { grouping: false }) }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate font-mono text-xs text-muted-foreground">{{ payment.reference_code ?? '—' }}</span>
                                <PaymentStatusBadge :status="payment.status" />
                            </div>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2.5 border-t border-dashed pt-2.5">
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Brand</dt>
                                    <dd class="truncate text-[13px]">{{ payment.brand_name || '—' }}</dd>
                                </div>
                                <div v-if="canViewStripeAccount" class="min-w-0">
                                    <dt class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Provider</dt>
                                    <dd class="text-[13px]"><PaymentProviderBadge :provider="payment.provider" show-name /></dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Country</dt>
                                    <dd class="truncate text-[13px]" :class="{ 'text-muted-foreground': !payment.country_name }">{{ payment.country_name ?? '—' }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">RM</dt>
                                    <dd class="truncate text-[13px]" :class="{ 'text-muted-foreground': !payment.relationship_manager_name }">{{ payment.relationship_manager_name ?? '—' }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Created</dt>
                                    <dd class="truncate text-[13px]">{{ formatDate(payment.created_at) }}</dd>
                                </div>
                            </dl>
                        </div>
                        <p v-if="isAdmin && payment.card_locked_until" class="flex items-start gap-1.5 border-t border-red-200 bg-red-50 px-3.5 py-2 text-xs text-red-600">
                            <LockKeyhole class="mt-0.5 size-3 shrink-0" />
                            Card payments paused until {{ lockedUntilLabel(payment.card_locked_until) }}. Customer should contact their representative.
                        </p>
                        <div v-if="!readOnly" class="flex items-center gap-2 border-t px-3.5 py-2">
                            <Button variant="outline" class="size-10" as-child aria-label="View payment details">
                                <Link :href="`/payments/${payment.uuid}`"><Eye class="size-[18px]" /></Link>
                            </Button>
                            <Button variant="outline" class="size-10" aria-label="Copy payment link" @click="copyLink(payment.uuid, payment.reference_slug)">
                                <Check v-if="copiedUuid === payment.uuid" class="size-[18px] text-green-600" />
                                <Copy v-else class="size-[18px]" />
                            </Button>
                            <Button v-if="payment.status === 'pending'" variant="outline" class="size-10" as-child aria-label="Edit payment">
                                <Link :href="`/payments/${payment.uuid}/edit`"><Pencil class="size-[18px]" /></Link>
                            </Button>
                            <Button
                                v-if="isAdmin && payment.card_locked_until"
                                variant="outline"
                                class="size-10"
                                aria-label="Release card lock"
                                :disabled="resetting === payment.uuid"
                                @click="resetLock(payment)"
                            >
                                <LockOpen class="size-[18px] text-red-600" />
                            </Button>
                            <Button
                                v-if="isAdmin"
                                variant="outline"
                                class="ml-auto size-10 border-destructive/30"
                                aria-label="Delete payment"
                                :disabled="deleteForm.processing && deleteTarget?.uuid === payment.uuid"
                                @click="confirmDelete(payment)"
                            >
                                <Trash2 class="size-[18px] text-destructive" />
                            </Button>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-14 text-center text-sm text-muted-foreground">
                    <template v-if="hasActiveFilters">
                        No payments match your filters.
                        <button class="underline" @click="clearFilters">Clear filters to see all.</button>
                    </template>
                    <template v-else>
                        No payments yet. <Link href="/payments/create" class="underline">Create one.</Link>
                    </template>
                </div>
            </div>

            <!-- Tablet and up: table. Lower priority columns drop out on narrower screens. -->
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border bg-[#F7F5F2]">
                            <th class="px-4 py-3.5 text-left text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:px-5">#</th>
                            <th v-if="visibleColumns.reference_code" class="px-4 py-3.5 text-left text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:px-5">Reference</th>
                            <th v-if="visibleColumns.client" class="px-4 py-3.5 text-left text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:px-5">Client</th>
                            <th v-if="visibleColumns.amount" class="px-4 py-3.5 text-left text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:px-5">Amount</th>
                            <th v-if="visibleColumns.brand" class="hidden px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:table-cell">Brand</th>
                            <th v-if="canViewStripeAccount && visibleColumns.provider" class="hidden px-5 py-3.5 text-center text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:table-cell">Provider</th>
                            <th v-if="visibleColumns.country_name" class="hidden px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-widest text-muted-foreground xl:table-cell">Country</th>
                            <th v-if="visibleColumns.relationship_manager_name" class="hidden px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-widest text-muted-foreground xl:table-cell">RM</th>
                            <th v-if="visibleColumns.status" class="px-4 py-3.5 text-center text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:px-5">Status</th>
                            <th v-if="visibleColumns.created" class="hidden px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-widest text-muted-foreground xl:table-cell">Created</th>
                            <th v-if="!readOnly" class="px-4 py-3.5 text-center text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:px-5">Actions</th>
                        </tr>
                    </thead>
                    <tbody v-if="refreshing">
                        <tr
                            v-for="n in skeletonRowCount"
                            :key="`skeleton-${n}`"
                            class="border-b border-border/50 last:border-0"
                        >
                            <td colspan="99" class="px-5 py-3.5">
                                <div class="h-4 animate-pulse rounded bg-muted" :style="{ width: `${55 + ((n * 13) % 40)}%` }"></div>
                            </td>
                        </tr>
                    </tbody>
                    <tbody v-else>
                        <tr
                            v-for="(payment, index) in payments.data"
                            :key="payment.id"
                            class="border-b border-border/50 transition-colors duration-150 last:border-0 hover:bg-muted/40"
                            :class="{ 'bg-red-50/70 hover:bg-red-50 dark:bg-red-950/20 dark:hover:bg-red-950/30': isAdmin && payment.card_locked_until }"
                        >
                            <td class="px-4 py-3.5 tabular-nums text-muted-foreground lg:px-5">{{ (payments.from ?? 1) + index }}</td>
                            <td v-if="visibleColumns.reference_code" class="px-4 py-3.5 font-mono text-muted-foreground lg:px-5">{{ payment.reference_code ?? '—' }}</td>
                            <td v-if="visibleColumns.client" class="px-4 py-3.5 font-medium lg:px-5">
                                {{ payment.client_name }}
                                <p v-if="isAdmin && payment.card_locked_until" class="mt-0.5 flex items-center gap-1 text-[11px] font-normal text-red-600 dark:text-red-400">
                                    <LockKeyhole class="size-3 shrink-0" />
                                    Card payments paused until {{ lockedUntilLabel(payment.card_locked_until) }}. Customer should contact their representative.
                                </p>
                            </td>
                            <td v-if="visibleColumns.amount" class="px-4 py-3.5 font-mono lg:px-5">{{ formatMoney(payment.amount, payment.currency, { grouping: false }) }}</td>
                            <td v-if="visibleColumns.brand" class="hidden px-5 py-3.5 lg:table-cell">{{ payment.brand_name }}</td>
                            <td v-if="canViewStripeAccount && visibleColumns.provider" class="hidden px-5 py-3.5 lg:table-cell">
                                <div class="flex justify-center">
                                    <PaymentProviderBadge :provider="payment.provider" />
                                </div>
                            </td>
                            <td v-if="visibleColumns.country_name" class="hidden px-5 py-3.5 xl:table-cell">{{ payment.country_name ?? '—' }}</td>
                            <td v-if="visibleColumns.relationship_manager_name" class="hidden px-5 py-3.5 xl:table-cell">{{ payment.relationship_manager_name ?? '—' }}</td>
                            <td v-if="visibleColumns.status" class="px-4 py-3.5 lg:px-5">
                                <div class="flex justify-center">
                                    <PaymentStatusBadge :status="payment.status" icon-only />
                                </div>
                            </td>
                            <td v-if="visibleColumns.created" class="hidden px-5 py-3.5 text-muted-foreground xl:table-cell">
                                {{ formatDate(payment.created_at) }}
                            </td>
                            <td v-if="!readOnly" class="px-4 py-3.5 text-right lg:px-5">
                                <div class="flex items-center justify-end gap-0.5">
                                    <Button variant="ghost" size="icon-sm" as-child title="View payment details">
                                        <Link :href="`/payments/${payment.uuid}`">
                                            <Eye class="size-3.5" />
                                        </Link>
                                    </Button>
                                    <Button variant="ghost" size="icon-sm" title="Copy payment link" @click="copyLink(payment.uuid, payment.reference_slug)">
                                        <Check v-if="copiedUuid === payment.uuid" class="size-3.5 text-green-600" />
                                        <Copy v-else class="size-3.5" />
                                    </Button>
                                    <Button
                                        v-if="payment.status === 'pending'"
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
                                        v-if="isAdmin && payment.card_locked_until"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="cursor-pointer"
                                        title="Release card lock"
                                        :disabled="resetting === payment.uuid"
                                        @click="resetLock(payment)"
                                    >
                                        <LockOpen class="size-3.5 text-red-600" />
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
                            <td colspan="99" class="px-5 py-16 text-center text-sm text-muted-foreground">
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

            <div v-if="payments.last_page > 1" class="border-t border-border/50 px-4 py-3 md:px-5 md:py-3.5">
                <!-- Phone: previous / next -->
                <nav v-if="isPhone" class="flex items-center justify-between gap-2" aria-label="Pagination">
                    <Button variant="outline" class="h-11 min-w-24" :disabled="payments.current_page === 1" @click="goToPage(payments.current_page - 1)">
                        Previous
                    </Button>
                    <span class="text-xs tabular-nums text-muted-foreground">Page {{ payments.current_page }} of {{ payments.last_page }}</span>
                    <Button variant="outline" class="h-11 min-w-24" :disabled="payments.current_page === payments.last_page" @click="goToPage(payments.current_page + 1)">
                        Next
                    </Button>
                </nav>

                <!-- Tablet and up: numbered pages -->
                <nav v-else class="flex items-center justify-center gap-0.5" aria-label="Pagination">
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
                                        ? 'scale-105 bg-primary text-primary-foreground shadow-sm'
                                        : 'text-foreground/60 hover:bg-muted hover:text-foreground',
                                ]"
                                @click="goToPage(item as number)"
                            >{{ item }}</button>
                        </template>
                    </div>

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
        :title="`Delete payment ${deleteTarget?.reference_code ?? ''}?`"
        description="This will remove the payment from the list. The payment link will no longer be accessible."
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
