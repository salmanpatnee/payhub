<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
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
});

function buildQuery(): Record<string, string> {
    const query: Record<string, string> = {};
    if (state.brand_id !== ALL) query.brand_id = state.brand_id;
    if (state.provider !== ALL) query.provider = state.provider;
    if (state.account !== ALL) query.account = state.account;
    if (state.currency !== ALL) query.currency = state.currency;
    if (state.from) query.from = state.from;
    if (state.to) query.to = state.to;
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
    </div>
</template>
