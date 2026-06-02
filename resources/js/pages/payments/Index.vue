<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useLocalStorage } from '@vueuse/core';
import { Check, Columns, Copy, Eye, Filter, Plus, Search, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
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

type PaymentRow = {
    id: number;
    uuid: string;
    reference_code: number | null;
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
    isAdmin: boolean;
    relationshipManagers: { id: number; name: string }[];
}>();

const copiedUuid = ref<string | null>(null);

const COLUMN_DEFS = [
    { key: 'reference_code', label: 'Reference Code' },
    { key: 'client', label: 'Client' },
    { key: 'amount', label: 'Amount' },
    { key: 'brand', label: 'Brand' },
    { key: 'account_name', label: 'Stripe Account' },
    { key: 'status', label: 'Status' },
    { key: 'created', label: 'Created' },
] as const;

const visibleColumns = useLocalStorage<Record<string, boolean>>(
    'payments.columns',
    Object.fromEntries(COLUMN_DEFS.map((c) => [c.key, true])),
    { mergeDefaults: true },
);

// # + Actions columns are always visible.
const visibleColumnCount = computed(
    () => 2 + COLUMN_DEFS.filter((c) => visibleColumns.value[c.key]).length,
);

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
    relationship_manager_id: props.filters.relationship_manager_id || UNSET,
    status: props.filters.status || UNSET,
    from: props.filters.from || '',
    to: props.filters.to || '',
    search: props.filters.search || '',
});

const hasActiveFilters = computed(() =>
    filters.brand_id !== UNSET ||
    filters.relationship_manager_id !== UNSET ||
    filters.status !== UNSET ||
    filters.from !== '' ||
    filters.to !== '' ||
    filters.search !== ''
);

const activeFilterCount = computed(() =>
    [
        filters.brand_id !== UNSET,
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

function clearFilters(): void {
    filters.brand_id = UNSET;
    filters.relationship_manager_id = UNSET;
    filters.status = UNSET;
    filters.from = '';
    filters.to = '';
    filters.search = '';
}

function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100);
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

function formatDate(iso: string): string {
    const d = new Date(iso);
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
            <Button as-child>
                <Link href="/payments/create">
                    <Plus class="size-4 mr-1" />
                    New payment
                </Link>
            </Button>
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

                <div v-if="isAdmin" class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Brand</Label>
                    <Select v-model="filters.brand_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All brands" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all">All Brands</SelectItem>
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

                <div class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">RM</Label>
                    <Select v-model="filters.relationship_manager_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All RMs" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all">All RMs</SelectItem>
                            <SelectItem
                                v-for="rm in relationshipManagers"
                                :key="rm.id"
                                :value="String(rm.id)"
                            >
                                {{ rm.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
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
                <DropdownMenu>
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
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-[#F7F5F2] border-b border-border">
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">#</th>
                        <th v-if="visibleColumns.reference_code" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Reference Code</th>
                        <th v-if="visibleColumns.client" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Client</th>
                        <th v-if="visibleColumns.amount" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Amount</th>
                        <th v-if="visibleColumns.brand" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Brand</th>
                        <th v-if="visibleColumns.account_name" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Stripe Account</th>
                        <th v-if="visibleColumns.status" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</th>
                        <th v-if="visibleColumns.created" class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Created</th>
                        <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(payment, index) in payments.data"
                        :key="payment.id"
                        class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                    >
                        <td class="px-5 py-3.5 text-muted-foreground tabular-nums">{{ (payments.from ?? 1) + index }}</td>
                        <td v-if="visibleColumns.reference_code" class="px-5 py-3.5 font-mono text-muted-foreground">{{ payment.reference_code != null ? '#' + String(payment.reference_code).padStart(6, '0') : '—' }}</td>
                        <td v-if="visibleColumns.client" class="px-5 py-3.5 font-medium">{{ payment.client_name }}</td>
                        <td v-if="visibleColumns.amount" class="px-5 py-3.5 font-mono">{{ formatAmount(payment.amount, payment.currency) }}</td>
                        <td v-if="visibleColumns.brand" class="px-5 py-3.5">{{ payment.brand_name }}</td>
                        <td v-if="visibleColumns.account_name" class="px-5 py-3.5">{{ payment.account_name }}</td>
                        <td v-if="visibleColumns.status" class="px-5 py-3.5">
                            <PaymentStatusBadge :status="payment.status" />
                        </td>
                        <td v-if="visibleColumns.created" class="px-5 py-3.5 text-muted-foreground">
                            {{ formatDate(payment.created_at) }}
                        </td>
                        <td class="px-5 py-3.5 text-right">
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
</template>
