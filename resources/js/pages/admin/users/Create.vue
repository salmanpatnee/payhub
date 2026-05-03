<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
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

defineProps<{ roles: string[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
            { title: 'Add user', href: '/admin/users/create' },
        ],
    },
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'user',
});

function submit() {
    form.post('/admin/users');
}
</script>

<template>
    <Head title="Add team member" />

    <div class="p-6 max-w-lg">
        <Card>
            <CardHeader>
                <CardTitle>Add team member</CardTitle>
                <CardDescription>
                    Create a new account. The user can change their password after logging in.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="create-user-form" class="space-y-4" @submit.prevent="submit">
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
                            required
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
                <Button variant="outline" as-child>
                    <Link href="/admin/users">Back to users</Link>
                </Button>
                <Button type="submit" form="create-user-form" :disabled="form.processing">
                    Create user
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
