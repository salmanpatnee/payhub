<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Loader2,
    Plus,
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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Whop Accounts', href: '/admin/whop-accounts' },
            { title: 'Add account', href: '#' },
        ],
    },
});

const form = useForm({
    account_name: '',
    company_id: '',
    environment: 'sandbox',
    webhook_secret: '',
    api_key: '',
    prefix: '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    router.post(
        '/admin/whop-accounts/test-connection',
        {
            api_key: form.api_key,
            company_id: form.company_id,
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
                testMessage.value =
                    e.whop_api ??
                    e.api_key ??
                    e.company_id ??
                    'Connection test failed.';
            },
        },
    );
}

function submit() {
    form.post('/admin/whop-accounts');
}
</script>

<template>
    <Head title="Add Whop account" />

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
                <CardTitle>Add Whop account</CardTitle>
                <CardDescription>
                    Enter the Merchant API secret key for this Whop account. The
                    secret key is stored encrypted and never displayed again
                    after saving.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    id="create-account-form"
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
                        <Input
                            id="api_key"
                            v-model="form.api_key"
                            type="password"
                            placeholder="apik_..."
                            autocomplete="new-password"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted. Never displayed after saving.
                        </p>
                        <InputError :message="form.errors.api_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="webhook_secret"
                            >Webhook signing secret
                            <span class="text-muted-foreground"
                                >(optional)</span
                            ></Label
                        >
                        <Input
                            id="webhook_secret"
                            v-model="form.webhook_secret"
                            type="password"
                            placeholder="ws_..."
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Leave blank. Run
                            <code>php artisan whop:register-webhook</code> to
                            create the webhook and store this automatically.
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
                    :disabled="
                        !form.api_key ||
                        !form.company_id ||
                        testStatus === 'testing'
                    "
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
                    form="create-account-form"
                    :disabled="form.processing"
                >
                    <Plus class="mr-1 size-4" />
                    Save account
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
