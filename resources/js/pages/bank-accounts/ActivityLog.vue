<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    ChevronDown,
    Clock,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Trash2,
} from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type ChangeEntry = {
    field: string;
    label: string;
    before: unknown;
    after: unknown;
};

type LogEntry = {
    id: number;
    action: string;
    action_label: string;
    actor_name: string;
    actor_role: string;
    subject_label: string;
    description: string;
    changes: ChangeEntry[] | null;
    created_at: string;
    bank_account_id: number;
};

type PaginatedLogs = {
    data: LogEntry[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    from: number | null;
    to: number | null;
};

type FilterState = {
    action: string;
    user_id: string;
    bank_account_id: string;
    status: string;
    from: string;
    to: string;
};

const props = defineProps<{
    logs: PaginatedLogs;
    filters: Partial<Record<keyof FilterState, string>>;
    users: { id: number; name: string }[];
    bankAccountOptions: { id: number; bank_name: string; account_name: string }[];
    actions: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Bank Accounts', href: '/bank-accounts' },
            { title: 'Activity Log', href: '/bank-accounts/activity-log' },
        ],
    },
});

const filters = reactive<FilterState>({
    action: props.filters.action || 'all',
    user_id: props.filters.user_id || 'all',
    bank_account_id: props.filters.bank_account_id || 'all',
    status: props.filters.status || 'all',
    from: props.filters.from || '',
    to: props.filters.to || '',
});

const hasActiveFilters = computed(
    () =>
        filters.action !== 'all' ||
        filters.user_id !== 'all' ||
        filters.bank_account_id !== 'all' ||
        filters.status !== 'all' ||
        filters.from !== '' ||
        filters.to !== '',
);

function buildQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (filters.action !== 'all') query.action = filters.action;
    if (filters.user_id !== 'all') query.user_id = filters.user_id;
    if (filters.bank_account_id !== 'all') query.bank_account_id = filters.bank_account_id;
    if (filters.status !== 'all') query.status = filters.status;
    if (filters.from) query.from = filters.from;
    if (filters.to) query.to = filters.to;

    return query;
}

watch(
    filters,
    () => {
        router.get('/bank-accounts/activity-log', buildQuery(), {
            preserveState: true,
            replace: true,
        });
    },
    { deep: true },
);

function clearFilters(): void {
    filters.action = 'all';
    filters.user_id = 'all';
    filters.bank_account_id = 'all';
    filters.status = 'all';
    filters.from = '';
    filters.to = '';
}

const pageItems = computed((): (number | '...')[] => {
    const current = props.logs.current_page;
    const last = props.logs.last_page;
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

function goToPage(page: number): void {
    router.get(
        '/bank-accounts/activity-log',
        { ...buildQuery(), page },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

type ActionMeta = {
    icon: typeof Plus;
    badge: string;
    markerBg: string;
    markerText: string;
    markerBorder: string;
    cardBorder: string;
};

const actionMeta: Record<string, ActionMeta> = {
    created: {
        icon: Plus,
        badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
        markerBg: 'bg-emerald-50 dark:bg-emerald-500/10',
        markerText: 'text-emerald-600 dark:text-emerald-400',
        markerBorder: 'border-emerald-200 dark:border-emerald-500/30',
        cardBorder: 'border-l-emerald-400 dark:border-l-emerald-500/50',
    },
    updated: {
        icon: Pencil,
        badge: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
        markerBg: 'bg-blue-50 dark:bg-blue-500/10',
        markerText: 'text-blue-600 dark:text-blue-400',
        markerBorder: 'border-blue-200 dark:border-blue-500/30',
        cardBorder: 'border-l-blue-400 dark:border-l-blue-500/50',
    },
    activated: {
        icon: Power,
        badge: 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-400',
        markerBg: 'bg-teal-50 dark:bg-teal-500/10',
        markerText: 'text-teal-600 dark:text-teal-400',
        markerBorder: 'border-teal-200 dark:border-teal-500/30',
        cardBorder: 'border-l-teal-400 dark:border-l-teal-500/50',
    },
    deactivated: {
        icon: PowerOff,
        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        markerBg: 'bg-amber-50 dark:bg-amber-500/10',
        markerText: 'text-amber-600 dark:text-amber-400',
        markerBorder: 'border-amber-200 dark:border-amber-500/30',
        cardBorder: 'border-l-amber-400 dark:border-l-amber-500/50',
    },
    deleted: {
        icon: Trash2,
        badge: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
        markerBg: 'bg-rose-50 dark:bg-rose-500/10',
        markerText: 'text-rose-600 dark:text-rose-400',
        markerBorder: 'border-rose-200 dark:border-rose-500/30',
        cardBorder: 'border-l-rose-400 dark:border-l-rose-500/50',
    },
};

function metaFor(action: string): ActionMeta {
    return actionMeta[action] ?? actionMeta.updated;
}

function formatDateTime(iso: string): string {
    const d = new Date(iso);
    const date = d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const time = d.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true });
    return `${date}, ${time}`;
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    return String(value);
}
</script>

<template>
    <Head title="Bank Account Activity Log" />

    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight">Activity Log</h1>
        </div>

        <!-- Filter bar -->
        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <div class="flex flex-wrap items-end gap-4 p-4">
                <div class="flex flex-col gap-1.5 flex-1 min-w-[9rem]">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Action</Label>
                    <Select v-model="filters.action">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All actions" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All actions</SelectItem>
                            <SelectItem v-for="a in actions" :key="a.value" :value="a.value">{{ a.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-[9rem]">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actor</Label>
                    <Select v-model="filters.user_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All actors" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All actors</SelectItem>
                            <SelectItem v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-[10rem]">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Bank Account</Label>
                    <Select v-model="filters.bank_account_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All accounts" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All accounts</SelectItem>
                            <SelectItem v-for="a in bankAccountOptions" :key="a.id" :value="String(a.id)">
                                {{ a.bank_name }} — {{ a.account_name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-[8rem]">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</Label>
                    <Select v-model="filters.status">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="deleted">Deleted</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-[8rem]">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">From</Label>
                    <Input v-model="filters.from" type="date" class="w-full" />
                </div>

                <div class="flex flex-col gap-1.5 flex-1 min-w-[8rem]">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">To</Label>
                    <Input v-model="filters.to" type="date" class="w-full" />
                </div>

                <button
                    v-if="hasActiveFilters"
                    class="h-9 px-2 text-xs text-muted-foreground underline"
                    @click="clearFilters"
                >
                    Clear all
                </button>
            </div>
        </div>

        <!-- Timeline -->
        <div v-if="logs.data.length > 0" class="relative">
            <div v-for="(log, index) in logs.data" :key="log.id" class="relative flex gap-4 pb-5 last:pb-0">
                <span
                    v-if="index !== logs.data.length - 1"
                    aria-hidden="true"
                    class="absolute top-9 bottom-0 left-[17px] w-px bg-border"
                />

                <div
                    :class="[
                        'relative z-10 flex size-9 shrink-0 items-center justify-center rounded-full border',
                        metaFor(log.action).markerBg,
                        metaFor(log.action).markerText,
                        metaFor(log.action).markerBorder,
                    ]"
                >
                    <component :is="metaFor(log.action).icon" class="size-4" />
                </div>

                <Card :class="['flex-1 gap-0 border-l-[3px] py-0 shadow-sm transition-shadow hover:shadow-md', metaFor(log.action).cardBorder]">
                    <CardContent class="p-4">
                        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-foreground">{{ log.actor_name }}</span>
                                <span class="inline-flex items-center rounded-md bg-muted px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    {{ log.actor_role }}
                                </span>
                                <span
                                    :class="[
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                        metaFor(log.action).badge,
                                    ]"
                                >
                                    {{ log.action_label }}
                                </span>
                            </div>

                            <div class="flex shrink-0 items-center gap-1 text-xs whitespace-nowrap text-muted-foreground">
                                <Clock class="size-3" />
                                {{ formatDateTime(log.created_at) }}
                            </div>
                        </div>

                        <p class="mt-2 text-sm leading-relaxed text-foreground/90">
                            {{ log.description }} <span class="font-medium text-foreground">{{ log.subject_label }}</span>
                        </p>

                        <Collapsible v-if="log.changes && log.changes.length > 0">
                            <CollapsibleTrigger class="group mt-3 inline-flex items-center gap-1.5 rounded-md border border-border/60 bg-muted/40 px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">
                                <ChevronDown class="size-3.5 transition-transform group-data-[state=open]:rotate-180" />
                                {{ log.changes.length }} field{{ log.changes.length > 1 ? 's' : '' }} changed
                            </CollapsibleTrigger>
                            <CollapsibleContent class="mt-2.5">
                                <div class="divide-y divide-border/40 rounded-lg border border-border/60 bg-muted/20 px-3">
                                    <div
                                        v-for="change in log.changes"
                                        :key="change.field"
                                        class="flex flex-wrap items-center gap-x-2 gap-y-1 py-2 text-xs"
                                    >
                                        <span class="w-full font-medium text-foreground/70 sm:w-28 sm:shrink-0">{{ change.label }}</span>
                                        <span class="max-w-[45%] truncate rounded bg-red-50 px-1.5 py-0.5 font-mono text-[11px] text-red-700 line-through decoration-red-300 dark:bg-red-500/10 dark:text-red-400 dark:decoration-red-800">
                                            {{ formatValue(change.before) }}
                                        </span>
                                        <ArrowRight class="size-3 shrink-0 text-muted-foreground" />
                                        <span class="max-w-[45%] truncate rounded bg-emerald-50 px-1.5 py-0.5 font-mono text-[11px] text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                            {{ formatValue(change.after) }}
                                        </span>
                                    </div>
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    </CardContent>
                </Card>
            </div>
        </div>

        <div v-else class="rounded-xl border border-border/70 bg-card p-16 text-center text-sm text-muted-foreground">
            <template v-if="hasActiveFilters">
                No activity matches your filters.
            </template>
            <template v-else>
                No activity recorded yet.
            </template>
        </div>

        <div v-if="logs.last_page > 1" class="flex items-center justify-center border-t border-border/50 px-5 py-3.5">
            <nav class="flex items-center gap-0.5" aria-label="Pagination">
                <button
                    :disabled="logs.current_page === 1"
                    class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                    title="First page"
                    @click="goToPage(1)"
                >«</button>
                <button
                    :disabled="logs.current_page === 1"
                    class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                    title="Previous page"
                    @click="goToPage(logs.current_page - 1)"
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
                                item === logs.current_page
                                    ? 'bg-primary text-primary-foreground shadow-sm scale-105'
                                    : 'text-foreground/60 hover:bg-muted hover:text-foreground',
                            ]"
                            @click="goToPage(item as number)"
                        >{{ item }}</button>
                    </template>
                </div>

                <button
                    :disabled="logs.current_page === logs.last_page"
                    class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                    title="Next page"
                    @click="goToPage(logs.current_page + 1)"
                >›</button>
                <button
                    :disabled="logs.current_page === logs.last_page"
                    class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                    title="Last page"
                    @click="goToPage(logs.last_page)"
                >»</button>
            </nav>
        </div>
    </div>
</template>
