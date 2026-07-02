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

type SquareAccountProp = {
    id: number;
    account_name: string;
    application_id: string;
    location_id: string;
    environment: 'sandbox' | 'production';
    currency: string | null;
    is_active: boolean;
    has_webhook_signature_key: boolean; // boolean — never the raw key value
    webhook_endpoint_url: string;       // assembled server-side: app.url + '/webhook/square/' + id
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Square Accounts', href: '/admin/square-accounts' },
            { title: 'Edit account', href: '#' },
        ],
    },
});

const props = defineProps<{ squareAccount: SquareAccountProp }>();

const form = useForm({
    _method:                'PUT',
    account_name:           props.squareAccount.account_name,
    application_id:          props.squareAccount.application_id,
    location_id:            props.squareAccount.location_id,
    environment:            props.squareAccount.environment,
    access_token:           '',   // blank = preserve existing
    webhook_signature_key:  '',   // blank = preserve existing
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');
const detectedCurrency = ref<string | null>(null);

const copiedEndpoint = ref(false);

async function copyEndpointUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.squareAccount.webhook_endpoint_url);
    } catch {
        const el = document.createElement('textarea');
        el.value = props.squareAccount.webhook_endpoint_url;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    copiedEndpoint.value = true;
    setTimeout(() => { copiedEndpoint.value = false; }, 2000);
}

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    detectedCurrency.value = null;
    const url = form.access_token
        ? '/admin/square-accounts/test-connection'
        : `/admin/square-accounts/${props.squareAccount.id}/test-connection`;
    const data = form.access_token
        ? { access_token: form.access_token, environment: form.environment, location_id: form.location_id }
        : {};
    router.post(url, data, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: (page) => {
            testStatus.value = 'ok';
            testMessage.value = 'Connected successfully.';
            detectedCurrency.value = (page.props.flash as Record<string, unknown> | undefined)?.detected_currency as string ?? null;
        },
        onError: (errors) => {
            const e = errors as Record<string, string>;
            testStatus.value = 'fail';
            testMessage.value = e.square_api ?? e.access_token ?? e.environment ?? e.location_id ?? 'Connection test failed.';
            detectedCurrency.value = null;
        },
    });
}

function submit() {
    form.post(`/admin/square-accounts/${props.squareAccount.id}`);
}
</script>

<template>
    <Head :title="`Edit ${squareAccount.account_name}`" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/square-accounts">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Edit Square account</CardTitle>
                <CardDescription>
                    Update the account name, IDs, or token. The current access token is not shown —
                    paste a new value to replace it.
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
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="squareAccount.currency
                                    ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400'
                                    : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400'"
                            >
                                {{ squareAccount.currency?.toUpperCase() ?? 'Unknown' }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Detected from the Square location. Test the connection after changing the location ID to refresh it.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="application_id">Application ID</Label>
                        <Input
                            id="application_id"
                            v-model="form.application_id"
                            type="text"
                            placeholder="sandbox-sq0idb-..."
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.application_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="location_id">Location ID</Label>
                        <Input
                            id="location_id"
                            v-model="form.location_id"
                            type="text"
                            placeholder="L..."
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.location_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="access_token">Access token</Label>
                        <div class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 text-sm font-mono tracking-widest text-muted-foreground">
                            ••••••••••••••••••••
                        </div>
                        <Input
                            id="access_token"
                            v-model="form.access_token"
                            type="password"
                            placeholder="Paste new token to replace"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current token. Paste a new value to replace it.
                        </p>
                        <InputError :message="form.errors.access_token" />
                    </div>

                    <!-- Webhook Endpoint URL (read-only + copy) -->
                    <div class="grid gap-2">
                        <Label>Webhook Endpoint URL</Label>
                        <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3">
                            <span class="flex-1 truncate select-all font-mono text-sm">{{ squareAccount.webhook_endpoint_url }}</span>
                            <Button type="button" variant="outline" size="sm" @click="copyEndpointUrl">
                                <Check v-if="copiedEndpoint" class="mr-1 size-4 text-green-600" />
                                <Copy v-else class="mr-1 size-4" />
                                {{ copiedEndpoint ? 'Copied!' : 'Copy' }}
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Paste this URL into the Square webhook subscription (event: payment.updated).
                            It must byte-match the subscription's notification URL or signature verification fails.
                        </p>
                    </div>

                    <!-- Webhook signature key (masked, blank = preserve) -->
                    <div class="grid gap-2">
                        <Label for="webhook_signature_key">Webhook signature key</Label>
                        <div
                            v-if="squareAccount.has_webhook_signature_key"
                            class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 font-mono text-sm tracking-widest text-muted-foreground"
                        >
                            ••••••••••••••••••••
                        </div>
                        <Input
                            id="webhook_signature_key"
                            v-model="form.webhook_signature_key"
                            type="password"
                            placeholder="Paste new signature key to replace"
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank to keep the current key. Paste a new value to replace it.
                        </p>
                        <InputError :message="form.errors.webhook_signature_key" />
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
                        <span v-else-if="testStatus === 'ok' && detectedCurrency">Connected successfully — currency: {{ detectedCurrency }}</span>
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
