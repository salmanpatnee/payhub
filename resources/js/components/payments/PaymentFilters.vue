<script setup lang="ts">
import { Search } from 'lucide-vue-next';
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

export type PaymentFilterState = {
    brand_id: string;
    country: string;
    provider: string;
    relationship_manager_id: string;
    status: string;
    from: string;
    to: string;
    search: string;
};

// The filter fields of the payments list. One copy, used inline on tablet and
// desktop and inside the filters sheet on phones. It edits the reactive
// `filters` object it is given; the page watches that object and reloads.
const model = defineModel<PaymentFilterState>('filters', { required: true });

defineProps<{
    brands: { id: number; name: string }[];
    countries: { id: string; name: string }[];
    relationshipManagers: { id: number; name: string }[];
    canViewStripeAccount: boolean;
    /** On phones the search box sits outside the sheet, so it is left out here. */
    showSearch?: boolean;
}>();

const UNSET = '__all';
const labelClass = 'text-[11px] font-semibold uppercase tracking-widest text-muted-foreground';
</script>

<template>
    <div v-if="showSearch" class="flex min-w-0 flex-col gap-1.5 md:col-span-2">
        <Label :class="labelClass">Search</Label>
        <div class="relative">
            <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
                v-model="model.search"
                type="search"
                placeholder="Name, email, reference or amount…"
                class="pl-8"
            />
        </div>
    </div>

    <div v-if="brands.length" class="flex min-w-0 flex-col gap-1.5">
        <Label :class="labelClass">Brand</Label>
        <SearchableSelect
            v-model="model.brand_id"
            :options="brands"
            :all-value="UNSET"
            all-label="All Brands"
            placeholder="All brands"
            search-placeholder="Search brands…"
        />
    </div>

    <div class="flex min-w-0 flex-col gap-1.5">
        <Label :class="labelClass">Country</Label>
        <SearchableSelect
            v-model="model.country"
            :options="countries"
            :all-value="UNSET"
            all-label="All Countries"
            placeholder="All countries"
            search-placeholder="Search countries…"
        />
    </div>

    <div v-if="canViewStripeAccount" class="flex min-w-0 flex-col gap-1.5">
        <Label :class="labelClass">Provider</Label>
        <Select v-model="model.provider">
            <SelectTrigger class="w-full">
                <SelectValue placeholder="All providers" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="__all">All Providers</SelectItem>
                <SelectItem value="stripe">Stripe</SelectItem>
                <SelectItem value="revolut">Revolut</SelectItem>
                <SelectItem value="square">Square</SelectItem>
                <SelectItem value="viva">Viva</SelectItem>
                <SelectItem value="clover">Clover</SelectItem>
                <SelectItem value="mpgs">Bank Alfalah</SelectItem>
                <SelectItem value="authorizenet">Authorize.net</SelectItem>
                <SelectItem value="whop">Whop</SelectItem>
                <SelectItem value="kurv">Kurv</SelectItem>
                <SelectItem value="nmi">NMI</SelectItem>
                <SelectItem value="worldpay">Worldpay</SelectItem>
            </SelectContent>
        </Select>
    </div>

    <div class="flex min-w-0 flex-col gap-1.5">
        <Label :class="labelClass">RM</Label>
        <SearchableSelect
            v-model="model.relationship_manager_id"
            :options="relationshipManagers"
            :all-value="UNSET"
            all-label="All RMs"
            placeholder="All RMs"
            search-placeholder="Search RMs…"
        />
    </div>

    <div class="flex min-w-0 flex-col gap-1.5">
        <Label :class="labelClass">Status</Label>
        <Select v-model="model.status">
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

    <div class="flex min-w-0 flex-col gap-1.5">
        <Label :class="labelClass">From</Label>
        <Input v-model="model.from" type="date" class="w-full" />
    </div>

    <div class="flex min-w-0 flex-col gap-1.5">
        <Label :class="labelClass">To</Label>
        <Input v-model="model.to" type="date" class="w-full" />
    </div>
</template>
