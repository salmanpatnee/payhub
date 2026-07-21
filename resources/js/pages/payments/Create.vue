<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Lock, Plus } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card, CardContent, CardDescription,
    CardFooter, CardHeader, CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select, SelectContent, SelectItem,
    SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

type BrandOption = { id: number; name: string };
type AccountOption = { id: number; account_name?: string; provider: string; currency?: string };
type RmOption = { id: number; name: string };
type AgentCurrencyOption = { currency: string; enabled: boolean; reason: string | null };

const props = defineProps<{
    brands: BrandOption[];
    accounts: AccountOption[];
    agentCurrencies: AgentCurrencyOption[];
    isAccountLocked: boolean;
    relationshipManagers: RmOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'New payment', href: '/payments/create' },
        ],
    },
});

const form = useForm({
    brand_id:                  '',
    provider:                  '',
    account_id:                '',
    relationship_manager_id:   '',
    currency:                  '',
    amount:                    '',
    client_name:               '',
    client_email:              '',
    service:                   '',
    package:                   '',
    note:                      '',
});

const providerLabel: Record<string, string> = { stripe: 'Stripe', revolut: 'Revolut', square: 'Square', viva: 'Viva' };

// The account selector encodes "provider:id" since ids collide across providers.
const accountValue = ref('');
const isCurrencyLocked = computed(() => props.accounts.find(a => `${a.provider}:${a.id}` === accountValue.value)?.currency != null);
watch(accountValue, (val) => {
    const [provider, id] = val.split(':');
    form.provider = provider ?? '';
    form.account_id = id ?? '';

    const account = props.accounts.find(a => `${a.provider}:${a.id}` === val);

    if (account?.currency) {
        form.currency = account.currency;
    }
});

onMounted(() => {
    if (props.isAccountLocked) {
        const enabledCurrencies = props.agentCurrencies.filter(c => c.enabled);

        if (enabledCurrencies.length === 1) {
            form.currency = enabledCurrencies[0].currency;
        }
    }
});


function submit() {
    form.post('/payments');
}
</script>

<template>
    <Head title="New Payment" />

    <div class="p-6 space-y-4">
        <Button variant="ghost" size="sm" as-child class="-ml-2">
            <Link href="/payments">
                <ArrowLeft class="size-4 mr-1" />
                Back to payments
            </Link>
        </Button>

        <Card>
            <CardHeader>
                <CardTitle>New payment</CardTitle>
                <CardDescription>Create a payment link to send to your client.</CardDescription>
            </CardHeader>
            <CardContent>
                <form id="create-payment-form" class="grid gap-x-6 gap-y-5 md:grid-cols-2 items-start" @submit.prevent="submit">

                    <!-- Fields are listed in row-pair order (left, right, left, right…) in a
                         single CSS grid so each row's height stays in sync across both columns —
                         a real grid, not two independent flex-column stacks, so nothing drifts. -->

                    <!-- Brand -->
                    <div class="grid gap-2">
                        <Label for="brand_id">Brand <span class="text-destructive">*</span></Label>
                        <SearchableSelect
                            id="brand_id"
                            v-model="form.brand_id"
                            required
                            :options="props.brands"
                            placeholder="Select a brand"
                            search-placeholder="Search brands…"
                        />
                        <InputError class="mt-1" :message="form.errors.brand_id" />
                    </div>

                    <!-- Payment account — hidden for agents (locked to their assigned account).
                         Selecting an account sets the provider (Stripe, Revolut or Square). -->
                    <div v-if="!isAccountLocked" class="grid gap-2">
                        <Label for="account_id">Payment Account <span class="text-destructive">*</span></Label>
                        <Select v-model="accountValue">
                            <SelectTrigger id="account_id" class="w-full">
                                <SelectValue placeholder="Select a payment account" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in props.accounts"
                                    :key="`${account.provider}:${account.id}`"
                                    :value="`${account.provider}:${account.id}`"
                                >{{ account.account_name }} ({{ providerLabel[account.provider] ?? account.provider }})</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-1" :message="form.errors.account_id" />
                    </div>

                    <!-- Relationship Manager -->
                    <div class="grid gap-2">
                        <Label for="relationship_manager_id">Relationship Manager <span class="text-destructive">*</span></Label>
                        <SearchableSelect
                            id="relationship_manager_id"
                            v-model="form.relationship_manager_id"
                            required
                            :options="props.relationshipManagers"
                            placeholder="Select a relationship manager"
                            search-placeholder="Search relationship managers…"
                        />
                        <InputError class="mt-1" :message="form.errors.relationship_manager_id" />
                    </div>

                    <!-- Currency — locked when the selected account is single-currency (e.g. Square),
                         or per-item disabled for agents based on their configured payment accounts. -->
                    <div class="grid gap-2">
                        <Label for="currency" class="gap-1.5">
                            Currency <span class="text-destructive">*</span>
                            <span
                                v-if="isCurrencyLocked"
                                class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-400"
                            >
                                <Lock class="size-3" />
                                Locked to this account's currency
                            </span>
                        </Label>
                        <Select v-model="form.currency" :disabled="isCurrencyLocked">
                            <SelectTrigger id="currency" class="w-full">
                                <SelectValue placeholder="Select currency" />
                            </SelectTrigger>
                            <SelectContent>
                                <template v-if="isAccountLocked">
                                    <SelectItem
                                        v-for="c in agentCurrencies"
                                        :key="c.currency"
                                        :value="c.currency"
                                        :disabled="!c.enabled"
                                    >
                                        {{ c.currency.toUpperCase() }}
                                        <span v-if="!c.enabled" class="text-muted-foreground text-xs">— {{ c.reason }}</span>
                                    </SelectItem>
                                </template>
                                <template v-else>
                                    <SelectItem value="usd">USD ($)</SelectItem>
                                    <SelectItem value="gbp">GBP (£)</SelectItem>
                                </template>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-1" :message="form.errors.currency" />
                    </div>

                    <!-- Amount -->
                    <div class="grid gap-2">
                        <Label for="amount">Amount <span class="text-destructive">*</span></Label>
                        <Input
                            id="amount"
                            v-model="form.amount"
                            type="number"
                            min="0.01"
                            step="0.01"
                            placeholder="0.00"
                        />
                        <InputError class="mt-1" :message="form.errors.amount" />
                    </div>

                    <!-- Client Name -->
                    <div class="grid gap-2">
                        <Label for="client_name">Client Name <span class="text-destructive">*</span></Label>
                        <Input
                            id="client_name"
                            v-model="form.client_name"
                            type="text"
                            placeholder="Jane Smith"
                        />
                        <InputError class="mt-1" :message="form.errors.client_name" />
                    </div>

                    <!-- Client Email -->
                    <div class="grid gap-2">
                        <Label for="client_email">Client Email</Label>
                        <Input
                            id="client_email"
                            v-model="form.client_email"
                            type="email"
                            placeholder="jane@example.com"
                        />
                        <InputError class="mt-1" :message="form.errors.client_email" />
                    </div>

                    <!-- Package -->
                    <div class="grid gap-2">
                        <Label for="package">Package</Label>
                        <Select v-model="form.package">
                            <SelectTrigger id="package" class="w-full">
                                <SelectValue placeholder="Select a package (optional)" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="basic">Basic</SelectItem>
                                <SelectItem value="standard">Standard</SelectItem>
                                <SelectItem value="premium">Premium</SelectItem>
                                <SelectItem value="platinum">Platinum</SelectItem>
                                <SelectItem value="diamond">Diamond</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-1" :message="form.errors.package" />
                    </div>

                    <!-- Note — spans full width -->
                    <div class="md:col-span-2 grid gap-2">
                        <Label for="note">Note <span class="text-muted-foreground text-xs">(internal only)</span></Label>
                        <Textarea
                            id="note"
                            v-model="form.note"
                            placeholder="Optional internal notes — not shown to client"
                            :rows="3"
                        />
                        <InputError class="mt-1" :message="form.errors.note" />
                    </div>

                </form>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button type="submit" form="create-payment-form" :disabled="form.processing">
                    <Plus class="size-4 mr-1" />
                    Create payment
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
