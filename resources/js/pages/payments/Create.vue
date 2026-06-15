<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import { ArrowLeft, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Card, CardContent, CardDescription,
    CardFooter, CardHeader, CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import {
    Select, SelectContent, SelectItem,
    SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

type BrandOption = { id: number; name: string };
type AccountOption = { id: number; account_name?: string; provider: string };
type RmOption = { id: number; name: string };

const props = defineProps<{
    brands: BrandOption[];
    accounts: AccountOption[];
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

const providerLabel: Record<string, string> = { stripe: 'Stripe', revolut: 'Revolut' };

// The account selector encodes "provider:id" since ids collide across providers.
const accountValue = ref('');
watch(accountValue, (val) => {
    const [provider, id] = val.split(':');
    form.provider = provider ?? '';
    form.account_id = id ?? '';
});

onMounted(() => {
    if (props.isAccountLocked && props.accounts.length > 0) {
        const a = props.accounts[0];
        accountValue.value = `${a.provider}:${a.id}`;
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
                <form id="create-payment-form" class="grid grid-cols-2 gap-4" @submit.prevent="submit">

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
                         Selecting an account sets the provider (Stripe or Revolut). -->
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

                    <!-- Currency -->
                    <div class="grid gap-2">
                        <Label for="currency">Currency <span class="text-destructive">*</span></Label>
                        <Select v-model="form.currency">
                            <SelectTrigger id="currency" class="w-full">
                                <SelectValue placeholder="Select currency" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="usd">USD ($)</SelectItem>
                                <SelectItem value="gbp">GBP (£)</SelectItem>
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
                        <Label for="client_name">Client Name</Label>
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
                    <div class="col-span-2 grid gap-2">
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
