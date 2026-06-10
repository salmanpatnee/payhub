<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RefreshCw, X } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';
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
    relationship_manager_id:
        props.filters.relationship_manager_id != null ? String(props.filters.relationship_manager_id) : ALL,
    stripe_account_id: props.filters.stripe_account_id != null ? String(props.filters.stripe_account_id) : ALL,
    currency: props.filters.currency ?? ALL,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const refreshing = ref(false);

function buildQuery(): Record<string, string> {
    const query: Record<string, string> = {};
    if (state.brand_id !== ALL) query.brand_id = state.brand_id;
    if (state.relationship_manager_id !== ALL) query.relationship_manager_id = state.relationship_manager_id;
    if (state.stripe_account_id !== ALL) query.stripe_account_id = state.stripe_account_id;
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
    state.relationship_manager_id = ALL;
    state.stripe_account_id = ALL;
    state.currency = ALL;
    state.from = '';
    state.to = '';
}

function refresh(): void {
    refreshing.value = true;
    router.reload({ onFinish: () => { refreshing.value = false; } });
}

const accountOptions = props.options.stripeAccounts.map((a) => ({ id: a.id, name: a.account_name }));
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
                <Label class="text-[11px] uppercase tracking-wider text-muted-foreground">RM</Label>
                <SearchableSelect
                    v-model="state.relationship_manager_id"
                    :options="options.relationshipManagers"
                    all-label="All RMs"
                    :all-value="ALL"
                    placeholder="All RMs"
                />
            </div>
            <div class="space-y-1">
                <Label class="text-[11px] uppercase tracking-wider text-muted-foreground">Account</Label>
                <SearchableSelect
                    v-model="state.stripe_account_id"
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
            <div class="flex items-end gap-2">
                <Button variant="outline" size="sm" class="h-9" @click="clearFilters">
                    <X class="size-4" />
                    <span class="sr-only md:not-sr-only md:ml-1">Clear</span>
                </Button>
                <Button variant="outline" size="sm" class="h-9" :disabled="refreshing" @click="refresh">
                    <RefreshCw class="size-4" :class="{ 'animate-spin': refreshing }" />
                </Button>
            </div>
        </div>
    </div>
</template>
