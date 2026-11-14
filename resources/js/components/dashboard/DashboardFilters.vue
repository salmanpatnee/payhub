<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarCheck, X } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import type { DashboardFilterOptions, DashboardFilterValues } from '@/types/dashboard';

const props = defineProps<{
    filters: DashboardFilterValues;
    options: DashboardFilterOptions;
}>();

const ALL = '__all';

const state = reactive({
    brand_id: props.filters.brand_id != null ? String(props.filters.brand_id) : ALL,
    provider: props.filters.provider ?? ALL,
    account: props.filters.account ?? ALL,
    currency: props.filters.currency ?? ALL,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    received: props.filters.received ?? false,
});

function buildQuery(): Record<string, string> {
    const query: Record<string, string> = {};
    if (state.brand_id !== ALL) query.brand_id = state.brand_id;
    if (state.provider !== ALL) query.provider = state.provider;
    if (state.account !== ALL) query.account = state.account;
    if (state.currency !== ALL) query.currency = state.currency;
    if (state.from) query.from = state.from;
    if (state.to) query.to = state.to;
    if (state.received) query.received = '1';
    return query;
}

let debounce: ReturnType<typeof setTimeout> | undefined;
watch(
    state,
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            router.get(dashboard.url({ query: buildQuery() }), {}, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 250);
    },
);

function clearFilters(): void {
    state.brand_id = ALL;
    state.provider = ALL;
    state.account = ALL;
    state.currency = ALL;
    state.from = '';
    state.to = '';
    state.received = false;
}

// Account options narrow to the chosen provider; clear a now-incompatible account.
const accountOptions = computed(() =>
    props.options.accounts
        .filter((a) => state.provider === ALL || a.provider === state.provider)
        .map((a) => ({ id: a.value, name: a.name })),
);

watch(
    () => state.provider,
    () => {
        if (state.account !== ALL && !accountOptions.value.some((a) => a.id === state.account)) {
            state.account = ALL;
        }
    },
);
</script>

<template>
    <div class="sticky top-0 z-10 rounded-xl border border-border/70 bg-card/95 p-3 shadow-sm backdrop-blur">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-7">
            <div class="space-y-1">
                <Label class="text-[11px] uppercase tracking-wider text-muted-foreground">From</Label>
                <Input v-model="state.from" type="date" class="h-9" />
            </div>
            <div class="space-y-1">
                <Label class="text-[11px] uppercase tracking-wider text-muted-foreground">To</Label>
                <Input v-model="state.to" type="date" class="h-9" />
            </div>
            <div class="space-y-1">
                <Label class="text-[11px] uppercase tracking-wider text-muted-foreground">Brand</Label>
                <SearchableSelect
                    v-model="state.brand_id"
                    :options="options.brands"
                    all-label="All brands"
                    :all-value="ALL"
                    placeholder="All brands"
                />
            </div>
            <div class="space-y-1">
                <Label class="text-[11px] uppercase tracking-wider text-muted-foreground">Provider</Label>
                <Select v-model="state.provider">
                    <SelectTrigger class="h-9"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All providers</SelectItem>
                        <SelectItem value="stripe">Stripe</SelectItem>
                        <SelectItem value="revolut">Revolut</SelectItem>
                        <SelectItem value="square">Square</SelectItem>
                        <SelectItem value="viva">Viva</SelectItem>
                        <SelectItem value="clover">Clover</SelectItem>
                        <SelectItem value="mpgs">MPGS</SelectItem>
                        <SelectItem value="authorizenet">Authorize.net</SelectItem>
                        <SelectItem value="whop">Whop</SelectItem>
                        <SelectItem value="kurv">Kurv</SelectItem>
                        <SelectItem value="nmi">NMI</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="space-y-1">
                <Label class="text-[11px] uppercase tracking-wider text-muted-foreground">Account</Label>
                <SearchableSelect
                    v-model="state.account"
                    :options="accountOptions"
                    all-label="All accounts"
                    :all-value="ALL"
                    placeholder="All accounts"
                />
            </div>
            <div class="space-y-1">
                <Label class="text-[11px] uppercase tracking-wider text-muted-foreground">Currency</Label>
                <Select v-model="state.currency">
                    <SelectTrigger class="h-9"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All currencies</SelectItem>
                        <SelectItem value="usd">USD</SelectItem>
                        <SelectItem value="gbp">GBP</SelectItem>
                        <SelectItem value="pkr">PKR</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="flex items-end">
                <Button variant="outline" size="sm" class="h-9 w-full" @click="clearFilters">
                    <X class="size-4" />
                    <span class="sr-only md:not-sr-only md:ml-1">Clear</span>
                </Button>
            </div>
        </div>
        <label
            for="dashboard-received"
            class="mt-3 flex w-fit max-w-full cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 transition-colors"
            :class="
                state.received
                    ? 'border-primary/60 bg-primary/10'
                    : 'border-dashed border-border bg-muted/40 hover:border-primary/40 hover:bg-muted'
            "
        >
            <Checkbox id="dashboard-received" v-model="state.received" class="size-5" />
            <CalendarCheck
                class="size-5 shrink-0 transition-colors"
                :class="state.received ? 'text-primary' : 'text-muted-foreground'"
            />
            <span class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                <span class="text-sm font-semibold">Received in this period</span>
                <span v-if="state.received && !state.from && !state.to" class="text-xs font-medium text-amber-600">
                    Pick a date range for this to take effect.
                </span>
            </span>
        </label>
    </div>
</template>
