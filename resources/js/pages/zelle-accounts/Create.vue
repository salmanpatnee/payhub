<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import MultiSelectCombobox from '@/components/MultiSelectCombobox.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
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
import { ZELLE_ACCOUNT_CURRENCIES, ZELLE_ACCOUNT_CURRENCY_LABELS } from '@/lib/zelle-account-currencies';

type NamedOption = { id: number; name: string };

defineProps<{
    users: NamedOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Zelle Accounts', href: '/zelle-accounts' },
            { title: 'Add account', href: '/zelle-accounts/create' },
        ],
    },
});

const form = useForm({
    account_name: '',
    email: '',
    mobile_number: '',
    currency: '',
    is_active: true,
    user_ids: [] as number[],
});

function submit() {
    form.post('/zelle-accounts');
}
</script>

<template>
    <Head title="Add Zelle account" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/zelle-accounts">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to Zelle accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add Zelle account</CardTitle>
                <CardDescription>
                    Zelle details staff can share with clients. Email is required, mobile number is optional.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="create-zelle-account-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="account_name">Account holder name</Label>
                            <Input id="account_name" v-model="form.account_name" type="text" required autofocus />
                            <InputError :message="form.errors.account_name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="currency">Currency</Label>
                            <Select v-model="form.currency">
                                <SelectTrigger id="currency" class="w-full">
                                    <SelectValue placeholder="Select a currency" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="currency in ZELLE_ACCOUNT_CURRENCIES"
                                        :key="currency"
                                        :value="currency"
                                    >
                                        {{ ZELLE_ACCOUNT_CURRENCY_LABELS[currency] }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.currency" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="email">Email</Label>
                            <Input id="email" v-model="form.email" type="email" required />
                            <InputError :message="form.errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="mobile_number">Mobile number</Label>
                            <Input id="mobile_number" v-model="form.mobile_number" type="text" maxlength="20" placeholder="e.g. +1 555 123 4567" />
                            <InputError :message="form.errors.mobile_number" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label>Assigned users</Label>
                        <MultiSelectCombobox
                            v-model="form.user_ids"
                            :options="users"
                            placeholder="Select users"
                            search-placeholder="Search users…"
                            empty-text="No users found."
                        />
                        <InputError :message="form.errors.user_ids" />
                    </div>

                    <Label for="is_active" class="flex items-center gap-3">
                        <Checkbox id="is_active" v-model="form.is_active" />
                        <span>Active</span>
                    </Label>
                </form>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button type="submit" form="create-zelle-account-form" :disabled="form.processing">
                    <Plus class="size-4 mr-1" />
                    Save account
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
