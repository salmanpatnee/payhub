<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { ArrowLeft, Save } from 'lucide-vue-next';
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
type AccountOption = { id: number; account_name: string };
type RmOption = { id: number; name: string };
type PaymentData = {
    uuid: string;
    brand_id: number;
    stripe_account_id: number;
    relationship_manager_id: number | null;
    currency: string;
    amount: string;
    client_name: string | null;
    client_email: string | null;
    service: string | null;
    package: string | null;
    note: string | null;
};

const props = defineProps<{
    brands: BrandOption[];
    stripeAccounts: AccountOption[];
    isStripeAccountLocked: boolean;
    relationshipManagers: RmOption[];
    payment: PaymentData;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'Edit payment', href: '#' },
        ],
    },
});

const form = useForm({
    brand_id:                  String(props.payment.brand_id),
    stripe_account_id:         String(props.payment.stripe_account_id),
    relationship_manager_id:   props.payment.relationship_manager_id != null ? String(props.payment.relationship_manager_id) : '',
    currency:                  props.payment.currency,
    amount:                    props.payment.amount,
    client_name:               props.payment.client_name ?? '',
    client_email:              props.payment.client_email ?? '',
    service:                   props.payment.service ?? '',
    package:                   props.payment.package ?? '',
    note:                      props.payment.note ?? '',
});

onMounted(() => {
    if (props.isStripeAccountLocked && props.stripeAccounts.length > 0) {
        form.stripe_account_id = String(props.stripeAccounts[0].id);
    }
});


function submit() {
    form.patch(`/payments/${props.payment.uuid}`);
}
</script>

<template>
    <Head title="Edit Payment" />

    <div class="p-6 space-y-4">
        <Button variant="ghost" size="sm" as-child class="-ml-2">
            <Link href="/payments">
                <ArrowLeft class="size-4 mr-1" />
                Back to payments
            </Link>
        </Button>

        <Card>
            <CardHeader>
                <CardTitle>Edit payment</CardTitle>
                <CardDescription>Update the details of this pending payment link.</CardDescription>
            </CardHeader>
            <CardContent>
                <form id="edit-payment-form" class="grid grid-cols-2 gap-4" @submit.prevent="submit">

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

                    <!-- Stripe Account -->
                    <div class="grid gap-2">
                        <Label for="stripe_account_id">Stripe Account <span class="text-destructive">*</span></Label>
                        <Select v-model="form.stripe_account_id" :disabled="isStripeAccountLocked">
                            <SelectTrigger id="stripe_account_id" class="w-full">
                                <SelectValue placeholder="Select a Stripe account" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in props.stripeAccounts"
                                    :key="account.id"
                                    :value="String(account.id)"
                                >{{ account.account_name }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-1" :message="form.errors.stripe_account_id" />
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
                <Button type="submit" form="edit-payment-form" :disabled="form.processing">
                    <Save class="size-4 mr-1" />
                    Save changes
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
