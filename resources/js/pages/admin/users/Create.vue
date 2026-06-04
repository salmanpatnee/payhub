<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, UserPlus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import MultiSelectCombobox from '@/components/MultiSelectCombobox.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type AccountOption = { id: number; account_name: string };
type NamedOption = { id: number; name: string };

defineProps<{
    roles: string[];
    stripeAccounts: AccountOption[];
    brands: NamedOption[];
    relationshipManagers: NamedOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
            { title: 'Add user', href: '/admin/users/create' },
        ],
    },
});

const form = useForm({
    name:                       '',
    username:                   '',
    password:                   '',
    role:                       'agent',
    stripe_account_id:          '',
    brand_ids:                  [] as number[],
    relationship_manager_ids:   [] as number[],
});

function submit() {
    form.post('/admin/users');
}
</script>

<template>
    <Head title="Add team member" />

    <div class="p-6">
        <!-- Page header -->
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2 mb-4">
                <Link href="/admin/users">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to users
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add team member</CardTitle>
                <CardDescription>
                    Create a new account. The user can change their password after logging in.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="create-user-form" class="grid grid-cols-2 gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            autocomplete="name"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="username">Username</Label>
                        <Input
                            id="username"
                            v-model="form.username"
                            type="text"
                            autocomplete="username"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.username" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">Role</Label>
                        <Select v-model="form.role">
                            <SelectTrigger id="role" class="w-full">
                                <SelectValue placeholder="Select a role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Admin</SelectItem>
                                <SelectItem value="agent">Agent</SelectItem>
                                <SelectItem value="account">Account</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-2" :message="form.errors.role" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label for="stripe_account_id">Stripe Account <span class="text-destructive">*</span></Label>
                        <Select v-model="form.stripe_account_id" required>
                            <SelectTrigger id="stripe_account_id" class="w-full">
                                <SelectValue placeholder="Select a Stripe account" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in stripeAccounts"
                                    :key="account.id"
                                    :value="String(account.id)"
                                >{{ account.account_name }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-2" :message="form.errors.stripe_account_id" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label>Brands <span class="text-destructive">*</span></Label>
                        <MultiSelectCombobox
                            v-model="form.brand_ids"
                            :options="brands"
                            placeholder="Select brands"
                            search-placeholder="Search brands…"
                            empty-text="No brands found."
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.brand_ids" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label>Relationship Managers <span class="text-destructive">*</span></Label>
                        <MultiSelectCombobox
                            v-model="form.relationship_manager_ids"
                            :options="relationshipManagers"
                            placeholder="Select relationship managers"
                            search-placeholder="Search relationship managers…"
                            empty-text="No relationship managers found."
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.relationship_manager_ids" />
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button type="submit" form="create-user-form" :disabled="form.processing">
                    <UserPlus class="size-4 mr-1" />
                    Create user
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
