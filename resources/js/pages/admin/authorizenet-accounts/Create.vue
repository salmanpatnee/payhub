<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Loader2, Plus, XCircle, Zap } from 'lucide-vue-next';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Authorize.net Accounts', href: '/admin/authorizenet-accounts' },
            { title: 'Add account', href: '#' },
        ],
    },
});

const form = useForm({
    account_name:    '',
    prefix:          '',
    api_login_id:    '',
    transaction_key: '',
    signature_key:   '',
    environment:     'sandbox',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    router.post('/admin/authorizenet-accounts/test-connection',
        {
            api_login_id: form.api_login_id,
            transaction_key: form.transaction_key,
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
                testMessage.value = e.authorizenet_api ?? e.api_login_id ?? e.transaction_key ?? 'Connection test failed.';
            },
        },
    );
}

function submit() {
    form.post('/admin/authorizenet-accounts');
}
</script>

<template>
    <Head title="Add Authorize.net account" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/authorizenet-accounts">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add Authorize.net account</CardTitle>
                <CardDescription>
                    Enter the API credentials from the Authorize.net Merchant Interface
                    (Account &gt; Settings &gt; API Credentials &amp; Keys). The keys are stored encrypted.
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
                        <Label>Currency</Label>
                        <div class="flex gap-1">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400">USD</span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Authorize.net accounts accept USD only.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="environment">Environment</Label>
                        <select
                            id="environment"
                            v-model="form.environment"
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm shadow-xs"
                        >
                            <option value="sandbox">Sandbox (testing)</option>
                            <option value="production">Production (live)</option>
                        </select>
                        <InputError :message="form.errors.environment" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_login_id">API login ID</Label>
                        <Input
                            id="api_login_id"
                            v-model="form.api_login_id"
                            type="text"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.api_login_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="transaction_key">Transaction key</Label>
                        <Input
                            id="transaction_key"
                            v-model="form.transaction_key"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                        <InputError :message="form.errors.transaction_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="signature_key">Signature key (optional)</Label>
                        <Input
                            id="signature_key"
                            v-model="form.signature_key"
                            type="password"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Needed to accept webhook notifications. Without it, PayHub relies on its own checks of the transaction.
                        </p>
                        <InputError :message="form.errors.signature_key" />
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
                    :disabled="testStatus === 'testing' || !form.api_login_id || !form.transaction_key"
                    @click="testConnection"
                >
                    <Loader2 v-if="testStatus === 'testing'" class="size-4 mr-1 animate-spin" />
                    <Zap v-else class="size-4 mr-1" />
                    Test connection
                </Button>
                <Button type="submit" form="create-account-form" :disabled="form.processing">
                    <Plus class="size-4 mr-1" />
                    Add account
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
