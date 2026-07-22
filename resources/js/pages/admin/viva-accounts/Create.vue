<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Loader2, Plus, XCircle, Zap } from 'lucide-vue-next';
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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Viva Accounts', href: '/admin/viva-accounts' },
            { title: 'Add account', href: '#' },
        ],
    },
});

const form = useForm({
    account_name:  '',
    prefix:        '',
    environment:   'demo',
    client_id:     '',
    client_secret: '',
    merchant_id:   '',
    api_key:       '',
    source_code:   '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    router.post('/admin/viva-accounts/test-connection',
        {
            client_id: form.client_id,
            client_secret: form.client_secret,
            merchant_id: form.merchant_id,
            api_key: form.api_key,
            environment: form.environment,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                testStatus.value = 'ok';
                testMessage.value = 'Connected successfully.';
            },
            onError: (errors) => {
                const e = errors as Record<string, string>;
                testStatus.value = 'fail';
                testMessage.value = e.viva_api ?? e.client_id ?? e.client_secret ?? 'Connection test failed.';
            },
        }
    );
}

function submit() {
    form.post('/admin/viva-accounts');
}
</script>

<template>
    <Head title="Add Viva account" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/viva-accounts">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add Viva account</CardTitle>
                <CardDescription>
                    Viva payments are GBP-only. Enter the Smart Checkout client credentials and the
                    legacy merchant ID / API key pair. Secrets are stored encrypted and never
                    displayed again after saving.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="create-account-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="account_name">Account name</Label>
                        <Input
                            id="account_name"
                            v-model="form.account_name"
                            type="text"
                            placeholder="e.g. Acme Corp UK"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            A label to identify this account in the admin panel.
                        </p>
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
                                <SelectItem value="demo">Demo</SelectItem>
                                <SelectItem value="production">Production</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.environment" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="client_id">Client ID</Label>
                        <Input
                            id="client_id"
                            v-model="form.client_id"
                            type="text"
                            placeholder="Smart Checkout Client ID"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.client_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="client_secret">Client secret</Label>
                        <Input
                            id="client_secret"
                            v-model="form.client_secret"
                            type="password"
                            placeholder="Smart Checkout Client Secret"
                            autocomplete="new-password"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted. Never displayed after saving.
                        </p>
                        <InputError :message="form.errors.client_secret" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="merchant_id">Merchant ID</Label>
                        <Input
                            id="merchant_id"
                            v-model="form.merchant_id"
                            type="text"
                            placeholder="Legacy Merchant ID"
                            autocomplete="off"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Legacy Basic Auth pair, required for Smart Checkout order creation.
                        </p>
                        <InputError :message="form.errors.merchant_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_key">API key</Label>
                        <Input
                            id="api_key"
                            v-model="form.api_key"
                            type="password"
                            placeholder="Legacy API Key"
                            autocomplete="new-password"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted. Never displayed after saving.
                        </p>
                        <InputError :message="form.errors.api_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="source_code">Source code</Label>
                        <Input
                            id="source_code"
                            v-model="form.source_code"
                            type="text"
                            placeholder="4-digit payment source code"
                            autocomplete="off"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Viva's 4-digit payment source code, required on every order creation.
                            To show your brand logo on the Viva checkout page, upload it against this
                            source in Viva's dashboard (Sales → Online payments → Websites/apps).
                        </p>
                        <InputError :message="form.errors.source_code" />
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
                    :disabled="!form.client_id || !form.client_secret || !form.merchant_id || !form.api_key || testStatus === 'testing'"
                    @click="testConnection"
                >
                    <Loader2 v-if="testStatus === 'testing'" class="size-4 mr-1 animate-spin" />
                    <Zap v-else class="size-4 mr-1" />
                    Test connection
                </Button>
                <Button type="submit" form="create-account-form" :disabled="form.processing">
                    <Plus class="size-4 mr-1" />
                    Save account
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
