<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check } from 'lucide-vue-next';
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

type StripeAccountProp = {
    id: number;
    account_name: string;
    publishable_key: string;
    is_active: boolean;
    // secret_key: intentionally absent — never sent from backend
};

const props = defineProps<{
    brand: BrandProp;
    stripeAccount: StripeAccountProp;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
            { title: props.brand.name, href: `/admin/brands/${props.brand.id}/edit` },
            { title: 'Stripe Accounts', href: `/admin/brands/${props.brand.id}/stripe-accounts` },
            { title: props.stripeAccount.account_name, href: '#' },
        ],
    },
});

const form = useForm({
    account_name:    props.stripeAccount.account_name,
    publishable_key: props.stripeAccount.publishable_key,
    secret_key:      '',  // always blank on load — user pastes new key to replace
});

function submit() {
    // No file upload — regular patch is fine (no method spoofing needed)
    form.patch(`/admin/brands/${props.brand.id}/stripe-accounts/${props.stripeAccount.id}`);
}
</script>

<template>
    <Head :title="`Edit ${stripeAccount.account_name}`" />

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
                <CardTitle>Edit Stripe account</CardTitle>
                <CardDescription>
                    Update the account name or keys. The current secret key is not shown —
                    paste a new value to replace it.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <!-- Stripe API validation error alert -->
                <Alert v-if="form.errors.stripe_api" variant="destructive" class="mb-4">
                    <AlertTitle>Stripe key validation failed</AlertTitle>
                    <AlertDescription>{{ form.errors.stripe_api }}</AlertDescription>
                </Alert>

                <form id="edit-account-form" class="space-y-4" @submit.prevent="submit">
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

                    <!-- Secret key — NEVER pre-filled, always blank on load -->
                    <div class="grid gap-2">
                        <Label for="secret_key">Secret key</Label>
                        <Input
                            id="secret_key"
                            v-model="form.secret_key"
                            type="password"
                            placeholder="sk_••••••••••••••••"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current secret key. Paste a new key to replace it.
                        </p>
                        <InputError :message="form.errors.secret_key" />
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button type="submit" form="edit-account-form" :disabled="form.processing">
                    <Check class="size-4 mr-1" />
                    Save changes
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
