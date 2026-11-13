<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    Loader2,
    XCircle,
    Zap,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
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

/**
 * Shared Create and Edit form for NMI accounts (spec 0012, AC-1, AC-2). The private key and the webhook
 * signing key are write only: the page only ever learns whether they are set.
 */
type NmiAccountProp = {
    id: number;
    account_name: string;
    prefix: string | null;
    environment: string;
    currency: string;
    public_tokenization_key: string;
    three_ds_enabled: boolean;
    google_pay_enabled: boolean;
    has_private_key: boolean;
    has_webhook_signing_key: boolean;
};

const props = defineProps<{ account?: NmiAccountProp }>();

const editing = computed(() => props.account !== undefined);

const form = useForm({
    _method: props.account ? 'PUT' : 'POST',
    account_name: props.account?.account_name ?? '',
    prefix: props.account?.prefix ?? '',
    environment: props.account?.environment ?? 'sandbox',
    currency: props.account?.currency ?? 'usd',
    private_key: '',
    public_tokenization_key: props.account?.public_tokenization_key ?? '',
    webhook_signing_key: '',
    three_ds_enabled: props.account?.three_ds_enabled ?? false,
    google_pay_enabled: props.account?.google_pay_enabled ?? false,
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    // A new key typed in is tested before anything is saved; otherwise the stored key is tested.
    const useTyped = form.private_key !== '' || !props.account;
    const url = useTyped
        ? '/admin/nmi-accounts/test-connection'
        : `/admin/nmi-accounts/${props.account!.id}/test-connection`;
    const data = useTyped
        ? { private_key: form.private_key, environment: form.environment }
        : {};

    router.post(url, data, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            testStatus.value = 'ok';
            testMessage.value = 'Connected';
        },
        onError: (errors) => {
            const e = errors as Record<string, string>;
            testStatus.value = 'fail';
            testMessage.value = e.nmi_api ?? e.private_key ?? 'Connection test failed.';
        },
    });
}

function submit() {
    form.post(props.account ? `/admin/nmi-accounts/${props.account.id}` : '/admin/nmi-accounts');
}
</script>

<template>
    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/nmi-accounts">
                    <ArrowLeft class="mr-1 size-4" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ editing ? 'Edit NMI account' : 'Add NMI account' }}</CardTitle>
                <CardDescription>
                    The private key and the webhook signing key are stored encrypted and are never shown again.
                    <template v-if="editing">Leave a key blank to keep the saved one.</template>
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="nmi-account-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="account_name">Account name</Label>
                        <Input id="account_name" v-model="form.account_name" type="text" placeholder="e.g. Acme Corp Live" required />
                        <InputError :message="form.errors.account_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="prefix">Reference Prefix</Label>
                        <Input
                            id="prefix"
                            v-model="form.prefix"
                            type="text"
                            placeholder="e.g. NMI"
                            maxlength="10"
                            style="text-transform: uppercase"
                            @input="form.prefix = (form.prefix as string).toUpperCase()"
                        />
                        <p class="text-xs text-muted-foreground">Optional. Uppercase letters and digits only, max 10 characters.</p>
                        <InputError :message="form.errors.prefix" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="environment">Environment</Label>
                            <select id="environment" v-model="form.environment" class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs">
                                <option value="sandbox">Sandbox (testing)</option>
                                <option value="production">Production (live)</option>
                            </select>
                            <InputError :message="form.errors.environment" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="currency">Currency</Label>
                            <select id="currency" v-model="form.currency" class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs">
                                <option value="usd">USD</option>
                                <option value="gbp">GBP</option>
                            </select>
                            <p class="text-xs text-muted-foreground">One currency per account.</p>
                            <InputError :message="form.errors.currency" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="private_key">Private key</Label>
                        <Input
                            id="private_key"
                            v-model="form.private_key"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="editing && account?.has_private_key ? 'Saved. Paste a new key to replace it.' : ''"
                            :required="!editing"
                        />
                        <InputError :message="form.errors.private_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="public_tokenization_key">Public tokenization key</Label>
                        <Input
                            id="public_tokenization_key"
                            v-model="form.public_tokenization_key"
                            type="text"
                            placeholder="xxxxxx-xxxxxx-xxxxxx-xxxxxx"
                            autocomplete="off"
                            required
                        />
                        <p class="text-xs text-muted-foreground">Public. It is safe to show on the pay page.</p>
                        <InputError :message="form.errors.public_tokenization_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="webhook_signing_key">Webhook signing key (optional)</Label>
                        <Input
                            id="webhook_signing_key"
                            v-model="form.webhook_signing_key"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="editing && account?.has_webhook_signing_key ? 'Saved. Paste a new key to replace it.' : ''"
                        />
                        <p class="text-xs text-muted-foreground">
                            Without it, webhooks are off for this account and payments settle by lookup only.
                        </p>
                        <InputError :message="form.errors.webhook_signing_key" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.three_ds_enabled" type="checkbox" class="size-4" />
                            Ask for 3D Secure on card payments
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.google_pay_enabled" type="checkbox" class="size-4" />
                            Show Google Pay
                        </label>
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
                        <Loader2 v-if="testStatus === 'testing'" class="size-4 animate-spin" />
                        <CheckCircle2 v-else-if="testStatus === 'ok'" class="size-4" />
                        <XCircle v-else-if="testStatus === 'fail'" class="size-4" />
                        {{ testStatus === 'testing' ? 'Testing connection…' : testMessage }}
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-between">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="(!editing && !form.private_key) || testStatus === 'testing'"
                    @click="testConnection"
                >
                    <Loader2 v-if="testStatus === 'testing'" class="mr-1 size-4 animate-spin" />
                    <Zap v-else class="mr-1 size-4" />
                    Test connection
                </Button>
                <Button type="submit" form="nmi-account-form" :disabled="form.processing">
                    <Check class="mr-1 size-4" />
                    {{ editing ? 'Save changes' : 'Save account' }}
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
