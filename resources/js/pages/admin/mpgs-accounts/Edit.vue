<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, CheckCircle2, Loader2, XCircle, Zap } from 'lucide-vue-next';
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

type MpgsAccountProp = {
    id: number;
    account_name: string;
    prefix: string | null;
    merchant_id: string;
    api_version: number;
    gateway_host: string | null;
    is_active: boolean;
    has_integration_password: boolean;      // boolean — never the raw password
    has_webhook_secret: boolean;            // boolean — never the raw secret
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'MPGS Accounts', href: '/admin/mpgs-accounts' },
            { title: 'Edit account', href: '#' },
        ],
    },
});

const props = defineProps<{ mpgsAccount: MpgsAccountProp; webhookUrl: string }>();

const form = useForm({
    _method:         'PUT',
    account_name:    props.mpgsAccount.account_name,
    prefix:          props.mpgsAccount.prefix ?? '',
    merchant_id:     props.mpgsAccount.merchant_id,
    api_version:          props.mpgsAccount.api_version,
    gateway_host:         props.mpgsAccount.gateway_host ?? '',
    integration_password: '', // blank = preserve existing
    webhook_secret:       '', // blank = preserve existing
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    const usingNewSecrets = form.integration_password;
    const url = usingNewSecrets
        ? '/admin/mpgs-accounts/test-connection'
        : `/admin/mpgs-accounts/${props.mpgsAccount.id}/test-connection`;
    const data = usingNewSecrets
        ? {
            merchant_id: form.merchant_id,
            integration_password: form.integration_password,
            api_version: form.api_version,
            gateway_host: form.gateway_host,
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
            testMessage.value = e.mpgs_api ?? e.merchant_id ?? e.integration_password ?? 'Connection test failed.';
        },
    });
}

function submit() {
    form.post(`/admin/mpgs-accounts/${props.mpgsAccount.id}`);
}
</script>

<template>
    <Head :title="`Edit ${mpgsAccount.account_name}`" />

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
                <CardTitle>Edit MPGS account</CardTitle>
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
                            :placeholder="'Default'"
                            autocomplete="off"
                        />
                        <p class="text-xs text-muted-foreground">
                            The bank's test and live gateways are different hosts. Leave empty to use the default.
                        </p>
                        <InputError :message="form.errors.gateway_host" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="integration_password">Integration password</Label>
                        <div
                            v-if="mpgsAccount.has_integration_password"
                            class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 font-mono text-sm tracking-widest text-muted-foreground"
                        >
                            ••••••••••••••••••••
                        </div>
                        <Input
                            id="integration_password"
                            v-model="form.integration_password"
                            type="password"
                            placeholder="Paste new password to replace"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current password. Paste a new value to replace it.
                        </p>
                        <InputError :message="form.errors.integration_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="webhook_secret">Notification secret</Label>
                        <div
                            v-if="mpgsAccount.has_webhook_secret"
                            class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 font-mono text-sm tracking-widest text-muted-foreground"
                        >
                            ••••••••••••••••••••
                        </div>
                        <Input
                            id="webhook_secret"
                            v-model="form.webhook_secret"
                            type="password"
                            placeholder="Paste new secret to replace"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current secret. Paste a new value to replace it.
                        </p>
                        <InputError :message="form.errors.webhook_secret" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="webhook_url">Notification URL</Label>
                        <Input id="webhook_url" :model-value="webhookUrl" type="text" readonly />
                        <p class="text-xs text-muted-foreground">
                            PayHub sends this on every payment, so no gateway-wide setting is needed. Shown for reference.
                        </p>
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
