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
            { title: 'Kurv Accounts', href: '/admin/kurv-accounts' },
            { title: 'Add account', href: '#' },
        ],
    },
});

const form = useForm({
    account_name: '',
    environment: 'sandbox',
    api_key: '',
    prefix: '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    router.post(
        '/admin/kurv-accounts/test-connection',
        {
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
                testMessage.value =
                    e.kurv_api ??
                    e.api_key ??
                    'Connection test failed.';
            },
        },
    );
}

function submit() {
    form.post('/admin/kurv-accounts');
}
</script>

<template>
    <Head title="Add Kurv account" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/kurv-accounts">
                    <ArrowLeft class="mr-1 size-4" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add Kurv account</CardTitle>
                <CardDescription>
                    Enter the Merchant API secret key for this Kurv account. The
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
                            placeholder="e.g. SPKV"
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
                            SPKV-001254).
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
                        <Label for="api_key">API key</Label>
                        <Input
                            id="api_key"
                            v-model="form.api_key"
                            type="password"
                            placeholder="kp_test_..."
                            autocomplete="new-password"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted. Never displayed after saving.
                        </p>
                        <InputError :message="form.errors.api_key" />
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
