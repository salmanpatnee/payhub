<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
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

type UserProp = {
    id: number;
    name: string;
    email: string;
    roles: string[];
};

const props = defineProps<{ user: UserProp; roles: string[] }>();

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
    name:     props.user.name,
    email:    props.user.email,
    password: '',
    role:     props.user.roles[0] ?? 'user',
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

    <div class="p-6 max-w-lg">
        <Card>
            <CardHeader>
                <CardTitle>Edit team member</CardTitle>
                <CardDescription>
                    Update name, email, role, or password for this account.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="edit-user-form" class="space-y-4" @submit.prevent="submit">
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
                            required
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
                            <SelectTrigger id="role">
                                <SelectValue placeholder="Select a role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Admin</SelectItem>
                                <SelectItem value="user">User</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-2" :message="form.errors.role" />
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-between">
                <div class="flex items-center gap-2">
                    <Button variant="outline" as-child>
                        <Link href="/admin/users">Back to users</Link>
                    </Button>
                    <Button
                        v-if="!isSelf"
                        variant="destructive"
                        type="button"
                        @click="deleteOpen = true"
                    >
                        Delete user
                    </Button>
                </div>
                <Button type="submit" form="edit-user-form" :disabled="form.processing">
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
