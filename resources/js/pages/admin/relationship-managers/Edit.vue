<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check } from 'lucide-vue-next';
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

type RmProp = {
    id: number;
    name: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'RMs', href: '/admin/relationship-managers' },
            { title: 'Edit RM', href: '#' },
        ],
    },
});

const props = defineProps<{ rm: RmProp }>();

const form = useForm({
    _method: 'PUT',
    name: props.rm.name,
});

function submit() {
    form.post(`/admin/relationship-managers/${props.rm.id}`);
}
</script>

<template>
    <Head :title="`Edit ${rm.name}`" />

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
                <CardTitle>Edit RM</CardTitle>
                <CardDescription>Update the relationship manager's name.</CardDescription>
            </CardHeader>
            <CardContent>
                <form id="edit-rm-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" type="text" required autofocus />
                        <InputError :message="form.errors.name" />
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button type="submit" form="edit-rm-form" :disabled="form.processing">
                    <Check class="size-4 mr-1" />
                    Save changes
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
