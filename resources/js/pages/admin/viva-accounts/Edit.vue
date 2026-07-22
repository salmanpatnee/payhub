<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, CheckCircle2, Copy, Loader2, XCircle, Zap } from 'lucide-vue-next';
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

type VivaAccountProp = {
    id: number;
    account_name: string;
    prefix: string | null;
    client_id: string;
    merchant_id: string;
    source_code: string;
    environment: 'demo' | 'production';
    is_active: boolean;
    has_client_secret: boolean;             // boolean — never the raw secret value
    has_api_key: boolean;                   // boolean — never the raw key value
    has_webhook_verification_key: boolean;
    webhook_verify_url: string;             // GET handshake endpoint
    webhook_endpoint_url: string;           // POST event delivery endpoint
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Viva Accounts', href: '/admin/viva-accounts' },
            { title: 'Edit account', href: '#' },
        ],
    },
});

const props = defineProps<{ vivaAccount: VivaAccountProp }>();

const form = useForm({
    _method:       'PUT',
    account_name:  props.vivaAccount.account_name,
    prefix:        props.vivaAccount.prefix ?? '',
    environment:   props.vivaAccount.environment,
    client_id:     props.vivaAccount.client_id,
    client_secret: '', // blank = preserve existing
    merchant_id:   props.vivaAccount.merchant_id,
    api_key:       '', // blank = preserve existing
    source_code:   props.vivaAccount.source_code,
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

const copiedVerify = ref(false);
const copiedEndpoint = ref(false);

async function copyText(text: string, flag: typeof copiedVerify): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    flag.value = true;
    setTimeout(() => { flag.value = false; }, 2000);
}

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    const usingNewSecrets = form.client_secret || form.api_key;
    const url = usingNewSecrets
        ? '/admin/viva-accounts/test-connection'
        : `/admin/viva-accounts/${props.vivaAccount.id}/test-connection`;
    const data = usingNewSecrets
        ? {
            client_id: form.client_id,
            client_secret: form.client_secret,
            merchant_id: form.merchant_id,
            api_key: form.api_key,
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
            testMessage.value = e.viva_api ?? e.client_id ?? e.client_secret ?? 'Connection test failed.';
        },
    });
}

function submit() {
    form.post(`/admin/viva-accounts/${props.vivaAccount.id}`);
}
</script>

<template>
    <Head :title="`Edit ${vivaAccount.account_name}`" />

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
                <CardTitle>Edit Viva account</CardTitle>
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
                            placeholder="e.g. Acme Corp UK"
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
                                <SelectItem value="demo">Demo</SelectItem>
                                <SelectItem value="production">Production</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.environment" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Currency</Label>
                        <div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400">
                                GBP
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Viva payments are GBP-only across all accounts — this is not configurable per account.
                        </p>
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
                        <div
                            v-if="vivaAccount.has_client_secret"
                            class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 font-mono text-sm tracking-widest text-muted-foreground"
                        >
                            ••••••••••••••••••••
                        </div>
                        <Input
                            id="client_secret"
                            v-model="form.client_secret"
                            type="password"
                            placeholder="Paste new secret to replace"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current secret. Paste a new value to replace it.
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
                        <InputError :message="form.errors.merchant_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_key">API key</Label>
                        <div
                            v-if="vivaAccount.has_api_key"
                            class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 font-mono text-sm tracking-widest text-muted-foreground"
                        >
                            ••••••••••••••••••••
                        </div>
                        <Input
                            id="api_key"
                            v-model="form.api_key"
                            type="password"
                            placeholder="Paste new key to replace"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current key. Paste a new value to replace it.
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
                            To show your brand logo on the Viva checkout page, upload it against this
                            source in Viva's dashboard (Sales → Online payments → Websites/apps).
                        </p>
                        <InputError :message="form.errors.source_code" />
                    </div>

                    <!-- Webhook verification handshake URL (GET, read-only + copy) -->
                    <div class="grid gap-2">
                        <Label>Webhook verification URL</Label>
                        <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3">
                            <span class="flex-1 truncate select-all font-mono text-sm">{{ vivaAccount.webhook_verify_url }}</span>
                            <Button type="button" variant="outline" size="sm" @click="copyText(vivaAccount.webhook_verify_url, copiedVerify)">
                                <Check v-if="copiedVerify" class="mr-1 size-4 text-green-600" />
                                <Copy v-else class="mr-1 size-4" />
                                {{ copiedVerify ? 'Copied!' : 'Copy' }}
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Viva GETs this URL once to verify the webhook subscription before delivering events.
                        </p>
                    </div>

                    <!-- Webhook event delivery URL (POST, read-only + copy) -->
                    <div class="grid gap-2">
                        <Label>Webhook Endpoint URL</Label>
                        <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3">
                            <span class="flex-1 truncate select-all font-mono text-sm">{{ vivaAccount.webhook_endpoint_url }}</span>
                            <Button type="button" variant="outline" size="sm" @click="copyText(vivaAccount.webhook_endpoint_url, copiedEndpoint)">
                                <Check v-if="copiedEndpoint" class="mr-1 size-4 text-green-600" />
                                <Copy v-else class="mr-1 size-4" />
                                {{ copiedEndpoint ? 'Copied!' : 'Copy' }}
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Paste this URL into the Viva webhook subscription (event: TransactionPaymentCreated).
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
