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

type NamedOption = { id: number; name: string };

defineProps<{
    users: NamedOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Bank Accounts', href: '/bank-accounts' },
            { title: 'Add account', href: '/bank-accounts/create' },
        ],
    },
});

const form = useForm({
    bank_name: '',
    account_name: '',
    account_number: '',
    currency: '',
    sort_code: '',
    routing_number: '',
    iban: '',
    swift_bic: '',
    bank_address: '',
    bank_country: '',
    is_active: true,
    user_ids: [] as number[],
});

function submit() {
    form.post('/bank-accounts');
}
</script>

<template>
    <Head title="Add bank account" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/bank-accounts">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to bank accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add bank account</CardTitle>
                <CardDescription>
                    Company bank details staff can share with clients. At least one of sort code,
                    routing number, or IBAN is required.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="create-bank-account-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="bank_name">Bank name</Label>
                            <Input id="bank_name" v-model="form.bank_name" type="text" required autofocus />
                            <InputError :message="form.errors.bank_name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="account_name">Account name</Label>
                            <Input id="account_name" v-model="form.account_name" type="text" required />
                            <InputError :message="form.errors.account_name" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="account_number">Account number</Label>
                            <Input id="account_number" v-model="form.account_number" type="text" required />
                            <InputError :message="form.errors.account_number" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="currency">Currency</Label>
                            <Select v-model="form.currency">
                                <SelectTrigger id="currency" class="w-full">
                                    <SelectValue placeholder="Select a currency" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="usd">USD</SelectItem>
                                    <SelectItem value="gbp">GBP</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.currency" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="sort_code">Sort code</Label>
                            <Input id="sort_code" v-model="form.sort_code" type="text" placeholder="e.g. 12-34-56" />
                            <InputError :message="form.errors.sort_code" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="routing_number">Routing number</Label>
                            <Input id="routing_number" v-model="form.routing_number" type="text" placeholder="e.g. 021000021" />
                            <InputError :message="form.errors.routing_number" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="iban">IBAN</Label>
                            <Input id="iban" v-model="form.iban" type="text" />
                            <InputError :message="form.errors.iban" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="swift_bic">SWIFT/BIC</Label>
                            <Input id="swift_bic" v-model="form.swift_bic" type="text" />
                            <InputError :message="form.errors.swift_bic" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="bank_address">Bank address</Label>
                            <Input id="bank_address" v-model="form.bank_address" type="text" />
                            <InputError :message="form.errors.bank_address" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="bank_country">Bank country</Label>
                            <Input id="bank_country" v-model="form.bank_country" type="text" />
                            <InputError :message="form.errors.bank_country" />
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
                <Button type="submit" form="create-bank-account-form" :disabled="form.processing">
                    <Plus class="size-4 mr-1" />
                    Save account
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
