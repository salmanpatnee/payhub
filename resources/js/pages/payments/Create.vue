<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { ArrowLeft, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Card, CardContent, CardDescription,
    CardFooter, CardHeader, CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Select, SelectContent, SelectItem,
    SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

type BrandOption = { id: number; name: string };
type PaymentAccountOption = { value: string; label: string; provider: string };
type RmOption = { id: number; name: string };

const props = defineProps<{
    brands: BrandOption[];
    paymentAccounts: PaymentAccountOption[];
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
    payment_account:           '',
    relationship_manager_id:   '',
    currency:                  'usd',
    amount:                    '',
    client_name:               '',
    client_email:              '',
    service:                   '',
    package:                   '',
    note:                      '',
});

onMounted(() => {
    if (props.isAccountLocked && props.paymentAccounts.length > 0) {
        form.payment_account = props.paymentAccounts[0].value;
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
                        <Label for="brand_id">Brand</Label>
                        <Select v-model="form.brand_id">
                            <SelectTrigger id="brand_id" class="w-full">
                                <SelectValue placeholder="Select a brand" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="brand in props.brands"
                                    :key="brand.id"
                                    :value="String(brand.id)"
                                >{{ brand.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-1" :message="form.errors.brand_id" />
                    </div>

                    <!-- Payment Account (merged Stripe + Square) -->
                    <div class="grid gap-2">
                        <Label for="payment_account">Payment Account</Label>
                        <Select v-model="form.payment_account" :disabled="isAccountLocked">
                            <SelectTrigger id="payment_account" class="w-full">
                                <SelectValue placeholder="Select a payment account" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in props.paymentAccounts"
                                    :key="account.value"
                                    :value="account.value"
                                >{{ account.label }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-1" :message="form.errors.payment_account" />
                    </div>

                    <!-- Relationship Manager -->
                    <div class="grid gap-2">
                        <Label for="relationship_manager_id">Relationship Manager</Label>
                        <Select v-model="form.relationship_manager_id">
                            <SelectTrigger id="relationship_manager_id" class="w-full">
                                <SelectValue placeholder="Select a relationship manager" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="rm in props.relationshipManagers"
                                    :key="rm.id"
                                    :value="String(rm.id)"
                                >{{ rm.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-1" :message="form.errors.relationship_manager_id" />
                    </div>

                    <!-- Currency -->
                    <div class="grid gap-2">
                        <Label for="currency">Currency</Label>
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
                        <Label for="amount">Amount</Label>
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
