<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'RMs', href: '/admin/relationship-managers' },
            { title: 'Add RM', href: '/admin/relationship-managers/create' },
        ],
    },
});

const form = useForm({
    name: '',
});

function submit() {
    form.post('/admin/relationship-managers');
}
</script>

<template>
    <Head title="Add RM" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/relationship-managers">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to RMs
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add RM</CardTitle>
                <CardDescription>Add a new relationship manager. The name must be unique.</CardDescription>
            </CardHeader>
            <CardContent>
                <form id="create-rm-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" type="text" required autofocus />
                        <InputError :message="form.errors.name" />
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button type="submit" form="create-rm-form" :disabled="form.processing">
                    <Plus class="size-4 mr-1" />
                    Create RM
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
