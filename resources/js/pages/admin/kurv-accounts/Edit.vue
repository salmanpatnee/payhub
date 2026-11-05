<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
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

type KurvAccountProp = {
    id: number;
    account_name: string;
    environment: string;
    has_api_key: boolean;
    is_active: boolean;
    prefix: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Kurv Accounts', href: '/admin/kurv-accounts' },
            { title: 'Edit account', href: '#' },
        ],
    },
});

const props = defineProps<{ kurvAccount: KurvAccountProp }>();

const form = useForm({
    _method: 'PUT',
    account_name: props.kurvAccount.account_name,
    environment: props.kurvAccount.environment,
    api_key: '',
    prefix: props.kurvAccount.prefix ?? '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    const url = form.api_key
        ? '/admin/kurv-accounts/test-connection'
        : `/admin/kurv-accounts/${props.kurvAccount.id}/test-connection`;
    const data = form.api_key
        ? {
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
            testMessage.value =
                e.kurv_api ??
                e.api_key ??
                'Connection test failed.';
        },
    });
}

function submit() {
    form.post(`/admin/kurv-accounts/${props.kurvAccount.id}`);
}
</script>

<template>
    <Head :title="`Edit ${kurvAccount.account_name}`" />

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
                <CardTitle>Edit Kurv account</CardTitle>
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
