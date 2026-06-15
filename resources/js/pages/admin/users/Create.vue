<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eye, EyeOff, UserPlus } from 'lucide-vue-next';
import { ref, watch } from 'vue';
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

type AccountOption = { id: number; account_name: string; provider: string };
type NamedOption = { id: number; name: string };

defineProps<{
    roles: string[];
    accounts: AccountOption[];
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
    provider:                   '',
    account_id:                 '',
    brand_ids:                  [] as number[],
    relationship_manager_ids:   [] as number[],
});

const providerLabel: Record<string, string> = { stripe: 'Stripe', revolut: 'Revolut' };

// The account selector encodes "provider:id" since ids collide across providers.
const accountValue = ref('');
watch(accountValue, (val) => {
    const [provider, id] = val.split(':');
    form.provider = provider ?? '';
    form.account_id = id ?? '';
});

const showPassword = ref(false);

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
                        <div class="relative">
                            <Input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                class="pr-10"
                                required
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff v-if="showPassword" class="size-4" />
                                <Eye v-else class="size-4" />
                            </button>
                        </div>
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
                        <Label for="account_id">Payment Account <span class="text-destructive">*</span></Label>
                        <Select v-model="accountValue" required>
                            <SelectTrigger id="account_id" class="w-full">
                                <SelectValue placeholder="Select a payment account" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in accounts"
                                    :key="`${account.provider}:${account.id}`"
                                    :value="`${account.provider}:${account.id}`"
                                >{{ account.account_name }} ({{ providerLabel[account.provider] ?? account.provider }})</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-2" :message="form.errors.account_id" />
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
