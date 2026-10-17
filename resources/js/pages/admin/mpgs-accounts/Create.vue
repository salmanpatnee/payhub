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
            { title: 'MPGS Accounts', href: '/admin/mpgs-accounts' },
            { title: 'Add account', href: '#' },
        ],
    },
});

const props = defineProps<{ defaultApiVersion: number; defaultGatewayHost: string }>();

const form = useForm({
    account_name:         '',
    prefix:               '',
    merchant_id:          '',
    api_version:          props.defaultApiVersion,
    gateway_host:         '',
    integration_password: '',
    webhook_secret:       '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    router.post('/admin/mpgs-accounts/test-connection',
        {
            merchant_id: form.merchant_id,
            integration_password: form.integration_password,
            api_version: form.api_version,
            gateway_host: form.gateway_host,
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
                testMessage.value = e.mpgs_api ?? e.merchant_id ?? e.integration_password ?? 'Connection test failed.';
            },
        }
    );
}

function submit() {
    form.post('/admin/mpgs-accounts');
}
</script>

<template>
    <Head title="Add MPGS account" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/mpgs-accounts">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add MPGS account</CardTitle>
                <CardDescription>
                    Enter the merchant id, API version, and integration password for the Mastercard Gateway
                    Hosted Session API. The integration password and notification secret are stored
                    encrypted and never displayed again after saving.
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
                        <Label>Currency</Label>
                        <div class="flex gap-1">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400">USD</span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400">GBP</span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            MPGS accounts accept both currencies. A currency your bank has not enabled fails at pay time.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="merchant_id">Merchant ID</Label>
                        <Input
                            id="merchant_id"
                            v-model="form.merchant_id"
                            type="text"
                            placeholder="MPGS Merchant ID"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.merchant_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_version">API version</Label>
                        <Input
                            id="api_version"
                            v-model="form.api_version"
                            type="number"
                            min="1"
                            max="999"
                            required
                        />
                        <InputError :message="form.errors.api_version" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="gateway_host">Gateway host (optional)</Label>
                        <Input
                            id="gateway_host"
                            v-model="form.gateway_host"
                            type="url"
                            :placeholder="props.defaultGatewayHost"
                            autocomplete="off"
                        />
                        <p class="text-xs text-muted-foreground">
                            The bank's test and live gateways are different hosts. Leave empty to use the default.
                        </p>
                        <InputError :message="form.errors.gateway_host" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="integration_password">Integration password</Label>
                        <Input
                            id="integration_password"
                            v-model="form.integration_password"
                            type="password"
                            placeholder="Merchant integration password"
                            autocomplete="new-password"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted. Never displayed after saving.
                        </p>
                        <InputError :message="form.errors.integration_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="webhook_secret">Notification secret</Label>
                        <Input
                            id="webhook_secret"
                            v-model="form.webhook_secret"
                            type="password"
                            placeholder="Notification secret (optional)"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional. Stored encrypted. Without it the webhook route rejects every request and PayHub relies on the pay response and the resolver.
                        </p>
                        <InputError :message="form.errors.webhook_secret" />
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
                    :disabled="!form.merchant_id || !form.integration_password || testStatus === 'testing'"
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
