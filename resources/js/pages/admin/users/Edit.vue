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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type AccountOption = { id: number; account_name: string };

type UserProp = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    stripe_account_id: number | null;
};

const props = defineProps<{ user: UserProp; roles: string[]; stripeAccounts: AccountOption[] }>();

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
    name:               props.user.name,
    email:              props.user.email,
    password:           '',
    role:               props.user.roles[0] ?? 'agent',
    stripe_account_id:  props.user.stripe_account_id ? String(props.user.stripe_account_id) : '',
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
                    Update name, email, role, or password for this account.
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
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
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
