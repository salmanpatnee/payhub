<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    Copy,
    Loader2,
    XCircle,
    Zap,
} from 'lucide-vue-next';
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

type WhopAccountProp = {
    id: number;
    account_name: string;
    company_id: string;
    environment: string;
    has_api_key: boolean;
    is_active: boolean;
    prefix: string | null;
    has_webhook_secret: boolean; // boolean — never the raw secret value
    webhook_endpoint_url: string; // assembled server-side
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Whop Accounts', href: '/admin/whop-accounts' },
            { title: 'Edit account', href: '#' },
        ],
    },
});

const props = defineProps<{ whopAccount: WhopAccountProp }>();

const form = useForm({
    _method: 'PUT',
    account_name: props.whopAccount.account_name,
    company_id: props.whopAccount.company_id,
    environment: props.whopAccount.environment,
    api_key: '',
    webhook_secret: '', // blank = preserve existing
    prefix: props.whopAccount.prefix ?? '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

const copiedEndpoint = ref(false);

async function copyEndpointUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(
            props.whopAccount.webhook_endpoint_url,
        );
    } catch {
        const el = document.createElement('textarea');
        el.value = props.whopAccount.webhook_endpoint_url;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }

    copiedEndpoint.value = true;
    setTimeout(() => {
        copiedEndpoint.value = false;
    }, 2000);
}

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    const url = form.api_key
        ? '/admin/whop-accounts/test-connection'
        : `/admin/whop-accounts/${props.whopAccount.id}/test-connection`;
    const data = form.api_key
        ? {
              api_key: form.api_key,
              company_id: form.company_id,
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
            testMessage.value =
                e.whop_api ??
                e.api_key ??
                e.company_id ??
                'Connection test failed.';
        },
    });
}

function submit() {
    form.post(`/admin/whop-accounts/${props.whopAccount.id}`);
}
</script>

<template>
    <Head :title="`Edit ${whopAccount.account_name}`" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/whop-accounts">
                    <ArrowLeft class="mr-1 size-4" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Edit Whop account</CardTitle>
                <CardDescription>
                    Update the account name or keys. The current secret key is
                    not shown — paste a new value to replace it.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    id="edit-account-form"
                    class="space-y-4"
                    @submit.prevent="submit"
                >
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
                        <Label for="prefix">Reference Prefix</Label>
                        <Input
                            id="prefix"
                            v-model="form.prefix"
                            type="text"
                            placeholder="e.g. SPWH"
                            maxlength="10"
                            style="text-transform: uppercase"
                            @input="
                                form.prefix = (
                                    form.prefix as string
                                ).toUpperCase()
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional. Uppercase letters and digits only, max 10
                            chars. Used in payment reference codes (e.g.
                            SPWH-001254).
                        </p>
                        <InputError :message="form.errors.prefix" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="environment">Environment</Label>
                        <select
                            id="environment"
                            v-model="form.environment"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                        >
                            <option value="sandbox">Sandbox (testing)</option>
                            <option value="production">
                                Production (live)
                            </option>
                        </select>
                        <InputError :message="form.errors.environment" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="company_id">Company ID</Label>
                        <Input
                            id="company_id"
                            v-model="form.company_id"
                            type="text"
                            placeholder="biz_..."
                            autocomplete="off"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            The Whop company this account charges for. Two
                            active accounts cannot share one company.
                        </p>
                        <InputError :message="form.errors.company_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_key">API key</Label>
                        <div
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
                            Leave blank to keep the current key. Paste a new
                            value to replace it.
                        </p>
                        <InputError :message="form.errors.api_key" />
                    </div>

                    <!-- Webhook Endpoint URL (read-only + copy) -->
                    <div class="grid gap-2">
                        <Label>Webhook Endpoint URL</Label>
                        <div
                            class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3"
                        >
                            <span
                                class="flex-1 truncate font-mono text-sm select-all"
                                >{{ whopAccount.webhook_endpoint_url }}</span
                            >
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="copyEndpointUrl"
                            >
                                <Check
                                    v-if="copiedEndpoint"
                                    class="mr-1 size-4 text-green-600"
                                />
                                <Copy v-else class="mr-1 size-4" />
                                {{ copiedEndpoint ? 'Copied!' : 'Copy' }}
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Register this URL in the Whop dashboard when
                            creating a webhook.
                        </p>
                    </div>

                    <!-- Webhook signing secret (masked, blank = preserve) -->
                    <div class="grid gap-2">
                        <Label for="webhook_secret"
                            >Webhook signing secret</Label
                        >
                        <div
                            v-if="whopAccount.has_webhook_secret"
                            class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 font-mono text-sm tracking-widest text-muted-foreground"
                        >
                            ••••••••••••••••••••
                        </div>
                        <Input
                            id="webhook_secret"
                            v-model="form.webhook_secret"
                            type="password"
                            placeholder="Paste new webhook secret to replace"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current secret. Paste a new
                            value (starts with ws_) to replace it, or run
                            whop:register-webhook.
                        </p>
                        <InputError :message="form.errors.webhook_secret" />
                    </div>

                    <div
                        v-if="testStatus !== 'idle'"
                        class="flex items-center gap-2 rounded-md px-3 py-2 text-sm"
                        :class="
                            testStatus === 'ok'
                                ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400'
                                : testStatus === 'fail'
                                  ? 'bg-destructive/10 text-destructive'
                                  : 'text-muted-foreground'
                        "
                    >
                        <Loader2
                            v-if="testStatus === 'testing'"
                            class="size-4 animate-spin"
                        />
                        <CheckCircle2
                            v-else-if="testStatus === 'ok'"
                            class="size-4"
                        />
                        <XCircle
                            v-else-if="testStatus === 'fail'"
                            class="size-4"
                        />
                        {{
                            testStatus === 'testing'
                                ? 'Testing connection…'
                                : testMessage
                        }}
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
                    <Loader2
                        v-if="testStatus === 'testing'"
                        class="mr-1 size-4 animate-spin"
                    />
                    <Zap v-else class="mr-1 size-4" />
                    Test connection
                </Button>
                <Button
                    type="submit"
                    form="edit-account-form"
                    :disabled="form.processing"
                >
                    <Check class="mr-1 size-4" />
                    Save changes
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
