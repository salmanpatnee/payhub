<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, CheckCircle2, Loader2, XCircle, Zap } from 'lucide-vue-next';
import { ref } from 'vue';
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
import {
    Select, SelectContent, SelectItem,
    SelectTrigger, SelectValue,
} from '@/components/ui/select';

type CloverAccountProp = {
    id: number;
    account_name: string;
    prefix: string | null;
    merchant_id: string;
    api_access_key: string;                 // public identifier — safe to display
    environment: 'sandbox' | 'production';
    is_active: boolean;
    has_private_token: boolean;             // boolean — never the raw token value
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Clover Accounts', href: '/admin/clover-accounts' },
            { title: 'Edit account', href: '#' },
        ],
    },
});

const props = defineProps<{ cloverAccount: CloverAccountProp }>();

const form = useForm({
    _method:         'PUT',
    account_name:    props.cloverAccount.account_name,
    prefix:          props.cloverAccount.prefix ?? '',
    environment:     props.cloverAccount.environment,
    merchant_id:     props.cloverAccount.merchant_id,
    api_access_key:  props.cloverAccount.api_access_key,
    private_token:   '', // blank = preserve existing
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    const usingNewSecrets = form.private_token;
    const url = usingNewSecrets
        ? '/admin/clover-accounts/test-connection'
        : `/admin/clover-accounts/${props.cloverAccount.id}/test-connection`;
    const data = usingNewSecrets
        ? {
            merchant_id: form.merchant_id,
            private_token: form.private_token,
            environment: form.environment,
        }
        : {};
    router.post(url, data, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            testStatus.value = 'ok';
            testMessage.value = 'Connected successfully.';
        },
        onError: (errors) => {
            const e = errors as Record<string, string>;
            testStatus.value = 'fail';
            testMessage.value = e.clover_api ?? e.merchant_id ?? e.private_token ?? 'Connection test failed.';
        },
    });
}

function submit() {
    form.post(`/admin/clover-accounts/${props.cloverAccount.id}`);
}
</script>

<template>
    <Head :title="`Edit ${cloverAccount.account_name}`" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/clover-accounts">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Edit Clover account</CardTitle>
                <CardDescription>
                    Update the account name, IDs, or credentials. Current secrets are not shown —
                    paste a new value to replace them.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="edit-account-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="account_name">Account name</Label>
                        <Input
                            id="account_name"
                            v-model="form.account_name"
                            type="text"
                            placeholder="e.g. Acme Corp US"
                            required
                        />
                        <InputError :message="form.errors.account_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="prefix">Reference Prefix</Label>
                        <Input
                            id="prefix"
                            v-model="form.prefix"
                            type="text"
                            placeholder="e.g. ACME"
                            maxlength="10"
                            style="text-transform: uppercase"
                            @input="form.prefix = (form.prefix as string).toUpperCase()"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional. Uppercase letters and digits only, max 10 chars. Used in payment reference codes (e.g. SPER-001254).
                        </p>
                        <InputError :message="form.errors.prefix" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="environment">Environment</Label>
                        <Select v-model="form.environment">
                            <SelectTrigger id="environment" class="w-full">
                                <SelectValue placeholder="Select environment" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="sandbox">Sandbox</SelectItem>
                                <SelectItem value="production">Production</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.environment" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Currency</Label>
                        <div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400">
                                USD
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Clover payments are USD-only across all accounts — this is not configurable per account.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="merchant_id">Merchant ID</Label>
                        <Input
                            id="merchant_id"
                            v-model="form.merchant_id"
                            type="text"
                            placeholder="Clover Merchant ID"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.merchant_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_access_key">Public access key</Label>
                        <Input
                            id="api_access_key"
                            v-model="form.api_access_key"
                            type="text"
                            placeholder="Ecommerce API Access Key"
                            autocomplete="off"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Public identifier the embedded card form's SDK needs client-side. Not a secret.
                        </p>
                        <InputError :message="form.errors.api_access_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="private_token">Private token</Label>
                        <div
                            v-if="cloverAccount.has_private_token"
                            class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 font-mono text-sm tracking-widest text-muted-foreground"
                        >
                            ••••••••••••••••••••
                        </div>
                        <Input
                            id="private_token"
                            v-model="form.private_token"
                            type="password"
                            placeholder="Paste new token to replace"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current token. Paste a new value to replace it.
                        </p>
                        <InputError :message="form.errors.private_token" />
                    </div>

                    <div
                        v-if="testStatus !== 'idle'"
                        class="flex items-center gap-2 text-sm rounded-md px-3 py-2"
                        :class="testStatus === 'ok' ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400' : testStatus === 'fail' ? 'bg-destructive/10 text-destructive' : 'text-muted-foreground'"
                    >
                        <Loader2 v-if="testStatus === 'testing'" class="size-4 animate-spin" />
                        <CheckCircle2 v-else-if="testStatus === 'ok'" class="size-4" />
                        <XCircle v-else-if="testStatus === 'fail'" class="size-4" />
                        <span v-if="testStatus === 'testing'">Testing connection…</span>
                        <span v-else>{{ testMessage }}</span>
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-between">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="testStatus === 'testing'"
                    @click="testConnection"
                >
                    <Loader2 v-if="testStatus === 'testing'" class="size-4 mr-1 animate-spin" />
                    <Zap v-else class="size-4 mr-1" />
                    Test connection
                </Button>
                <Button type="submit" form="edit-account-form" :disabled="form.processing">
                    <Check class="size-4 mr-1" />
                    Save changes
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
