<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from 'lucide-vue-next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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

type BrandProp = { id: number; name: string };

const props = defineProps<{ brand: BrandProp }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
            { title: props.brand.name, href: `/admin/brands/${props.brand.id}/edit` },
            { title: 'Stripe Accounts', href: `/admin/brands/${props.brand.id}/stripe-accounts` },
            { title: 'Add account', href: '#' },
        ],
    },
});

const form = useForm({
    account_name:    '',
    publishable_key: '',
    secret_key:      '',
});

function submit() {
    form.post(`/admin/brands/${props.brand.id}/stripe-accounts`);
}
</script>

<template>
    <Head title="Add Stripe account" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link :href="`/admin/brands/${brand.id}/stripe-accounts`">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card class="max-w-lg">
            <CardHeader>
                <CardTitle>Add Stripe account</CardTitle>
                <CardDescription>
                    Enter the publishable and secret keys for this Stripe account.
                    The secret key is stored encrypted and never displayed again after saving.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <!-- Stripe API validation error alert -->
                <Alert v-if="form.errors.stripe_api" variant="destructive" class="mb-4">
                    <AlertTitle>Stripe key validation failed</AlertTitle>
                    <AlertDescription>{{ form.errors.stripe_api }}</AlertDescription>
                </Alert>

                <form id="create-account-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="account_name">Account name</Label>
                        <Input
                            id="account_name"
                            v-model="form.account_name"
                            type="text"
                            placeholder="e.g. Acme Corp Live"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            A label to identify this account in the admin panel.
                        </p>
                        <InputError :message="form.errors.account_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="publishable_key">Publishable key</Label>
                        <Input
                            id="publishable_key"
                            v-model="form.publishable_key"
                            type="text"
                            placeholder="pk_live_..."
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.publishable_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="secret_key">Secret key</Label>
                        <Input
                            id="secret_key"
                            v-model="form.secret_key"
                            type="password"
                            placeholder="sk_live_..."
                            autocomplete="new-password"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted. Never displayed after saving.
                        </p>
                        <InputError :message="form.errors.secret_key" />
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button type="submit" form="create-account-form" :disabled="form.processing">
                    <Plus class="size-4 mr-1" />
                    Save account
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
