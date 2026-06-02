<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Check } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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

type UserProp = {
    id: number;
    name: string;
    username: string;
    roles: string[];
    stripe_account_id: number | null;
    brand_ids: number[];
    relationship_manager_ids: number[];
};

const props = defineProps<{
    user: UserProp;
    roles: string[];
    stripeAccounts: AccountOption[];
    brands: NamedOption[];
    relationshipManagers: NamedOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
            { title: 'Edit user', href: '#' },
        ],
    },
});

const page     = usePage();
const isSelf   = computed(() => page.props.auth.user?.id === props.user.id);
const deleteOpen = ref(false);

const form = useForm({
    name:                       props.user.name,
    username:                   props.user.username,
    password:                   '',
    role:                       props.user.roles[0] ?? 'agent',
    stripe_account_id:          props.user.stripe_account_id ? String(props.user.stripe_account_id) : '',
    brand_ids:                  [...props.user.brand_ids],
    relationship_manager_ids:   [...props.user.relationship_manager_ids],
});

const deleteForm = useForm({});

function submit() {
    form.patch(`/admin/users/${props.user.id}`);
}

function executeDelete() {
    deleteForm.delete(`/admin/users/${props.user.id}`, {
        onSuccess: () => { deleteOpen.value = false; },
    });
}
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <div class="p-6">
        <!-- Back navigation -->
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/users">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to users
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Edit team member</CardTitle>
                <CardDescription>
                    Update name, username, role, or password for this account.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="edit-user-form" class="grid grid-cols-2 gap-4" @submit.prevent="submit">
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
                            placeholder="Leave blank to keep current password"
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
                            </SelectContent>
                        </Select>
                        <InputError class="mt-2" :message="form.errors.role" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label for="stripe_account_id">Stripe Account</Label>
                        <Select v-model="form.stripe_account_id">
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
                        <Label>Brands</Label>
                        <MultiSelectCombobox
                            v-model="form.brand_ids"
                            :options="brands"
                            placeholder="Select brands"
                            search-placeholder="Search brands…"
                            empty-text="No brands found."
                        />
                        <InputError class="mt-2" :message="form.errors.brand_ids" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label>Relationship Managers</Label>
                        <MultiSelectCombobox
                            v-model="form.relationship_manager_ids"
                            :options="relationshipManagers"
                            placeholder="Select relationship managers"
                            search-placeholder="Search relationship managers…"
                            empty-text="No relationship managers found."
                        />
                        <InputError class="mt-2" :message="form.errors.relationship_manager_ids" />
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex items-center justify-between">
                <Button
                    v-if="!isSelf"
                    variant="destructive"
                    type="button"
                    @click="deleteOpen = true"
                >
                    Delete user
                </Button>
                <div v-else />
                <Button type="submit" form="edit-user-form" :disabled="form.processing">
                    <Check class="size-4 mr-1" />
                    Save changes
                </Button>
            </CardFooter>
        </Card>
    </div>

    <!-- Delete confirmation dialog -->
    <Dialog v-model:open="deleteOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete user?</DialogTitle>
                <DialogDescription>
                    This will permanently remove {{ user.name }}'s account. This action cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deleteOpen = false">Keep account</Button>
                <Button
                    variant="destructive"
                    :disabled="deleteForm.processing"
                    @click="executeDelete"
                >
                    Delete user
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
