<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
type AccountOption = { id: number; account_name: string };

const props = defineProps<{
    brands: BrandOption[];
    stripeAccounts: AccountOption[];
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
    brand_id:          '',
    stripe_account_id: '',
    currency:          'usd',
    amount:            '',
    client_name:       '',
    client_email:      '',
    service:           '',
    package:           '',
    note:              '',
});

// D-14: Fee breakdown — client-side only, no server round-trip
// USD: 2.9% + $0.30 | GBP: 1.5% + £0.20
const feeBreakdown = computed(() => {
    const amt = parseFloat(form.amount as string);
    if (!amt || amt <= 0) return null;

    const fee     = form.currency === 'gbp' ? amt * 0.015 + 0.20 : amt * 0.029 + 0.30;
    const receive = amt - fee;
    const locale  = form.currency === 'gbp' ? 'en-GB' : 'en-US';
    const curr    = form.currency.toUpperCase();
    const fmt     = (n: number) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: curr }).format(n);

    return { charge: fmt(amt), fee: fmt(fee), receive: fmt(receive) };
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

                    <!-- Stripe Account -->
                    <div class="grid gap-2">
                        <Label for="stripe_account_id">Stripe Account</Label>
                        <Select v-model="form.stripe_account_id">
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

                    <!-- Fee Breakdown (D-14) — spans full width, only when amount > 0 -->
                    <div v-if="feeBreakdown" class="col-span-2 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-2">
                            Estimated — based on standard Stripe rates
                        </p>
                        <div class="flex justify-between py-1">
                            <span class="text-muted-foreground">Charge amount</span>
                            <span class="font-medium">{{ feeBreakdown.charge }}</span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span class="text-muted-foreground">Stripe fee</span>
                            <span class="text-destructive">− {{ feeBreakdown.fee }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-t border-border mt-1 pt-2">
                            <span class="font-semibold">You receive</span>
                            <span class="font-semibold text-green-600">{{ feeBreakdown.receive }}</span>
                        </div>
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

                    <!-- Service -->
                    <div class="grid gap-2">
                        <Label for="service">Service</Label>
                        <Input
                            id="service"
                            v-model="form.service"
                            type="text"
                            placeholder="e.g. Web Design"
                        />
                        <InputError class="mt-1" :message="form.errors.service" />
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
