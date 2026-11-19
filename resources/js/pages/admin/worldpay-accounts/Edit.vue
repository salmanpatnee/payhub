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

type WorldpayAccountProp = {
    id: number;
    account_name: string;
    prefix: string | null;
    environment: string;
    entity_ref: string;
    mode: string;
    checkout_id: string | null;
    google_pay_enabled: boolean;
    google_pay_gateway_merchant_id: string | null;
    has_payments: boolean;
    webhook_key_id: string | null;
    is_active: boolean;
    has_api_username: boolean;
    has_api_password: boolean;
    has_webhook_secret: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Worldpay Accounts', href: '/admin/worldpay-accounts' },
            { title: 'Edit account', href: '#' },
        ],
    },
});

const props = defineProps<{ worldpayAccount: WorldpayAccountProp }>();

const form = useForm({
    _method: 'PUT',
    account_name: props.worldpayAccount.account_name,
    prefix: props.worldpayAccount.prefix ?? '',
    environment: props.worldpayAccount.environment,
    entity_ref: props.worldpayAccount.entity_ref,
    mode: props.worldpayAccount.mode,
    checkout_id: props.worldpayAccount.checkout_id ?? '',
    google_pay_enabled: props.worldpayAccount.google_pay_enabled,
    google_pay_gateway_merchant_id:
        props.worldpayAccount.google_pay_gateway_merchant_id ?? '',
    api_username: '',
    api_password: '',
    webhook_key_id: props.worldpayAccount.webhook_key_id ?? '',
    webhook_secret: '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    // Blank fields are filled from the saved account on the server.
    const typed = form.api_username || form.api_password;
    const url = typed
        ? '/admin/worldpay-accounts/test-connection'
        : `/admin/worldpay-accounts/${props.worldpayAccount.id}/test-connection`;
    const data = typed
        ? {
              api_username: form.api_username,
              api_password: form.api_password,
              environment: form.environment,
              mode: form.mode,
              checkout_id: form.checkout_id,
              account_id: props.worldpayAccount.id,
          }
        : {};
    router.post(url, data, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            testStatus.value = 'ok';
            testMessage.value =
                form.mode === 'card_fields'
                        ? 'Connected. The Checkout ID is checked when the card fields load on a real payment page.'
                        : 'Connected. The entity is checked on the first real payment.';
        },
        onError: (errors) => {
            const e = errors as Record<string, string>;
            testStatus.value = 'fail';
            testMessage.value =
                e.worldpay_api ??
                e.api_username ??
                e.api_password ??
                'Connection test failed.';
        },
    });
}

function submit() {
    form.post(`/admin/worldpay-accounts/${props.worldpayAccount.id}`);
}
</script>

<template>
    <Head :title="`Edit ${worldpayAccount.account_name}`" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/worldpay-accounts">
                    <ArrowLeft class="mr-1 size-4" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Edit Worldpay account</CardTitle>
                <CardDescription>
                    Saved secrets are never shown. Leave a field blank to keep
                    the saved value, or paste a new one to replace it. Card
                    payments must capture automatically. With manual capture a
                    payment stays authorized and PayHub never marks it paid.
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
                            placeholder="e.g. SPWP"
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
                            chars.
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
                            <option value="try">Try (testing)</option>
                            <option value="live">Live (production)</option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            Locked once a payment uses this account.
                        </p>
                        <InputError :message="form.errors.environment" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="entity_ref">Entity reference</Label>
                        <Input
                            id="entity_ref"
                            v-model="form.entity_ref"
                            type="text"
                            maxlength="64"
                            required
                        />
                        <InputError :message="form.errors.entity_ref" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="mode">Payment mode</Label>
                        <select
                            id="mode"
                            v-model="form.mode"
                            :disabled="worldpayAccount.has_payments"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs disabled:opacity-60"
                        >
                            <option value="hosted">
                                Hosted page (the client pays on Worldpay)
                            </option>
                            <!-- Card fields has no pay page yet (spec 0015), so it is only kept for accounts already set to it. -->
                            <option
                                v-if="worldpayAccount.mode === 'card_fields'"
                                value="card_fields"
                            >
                                Card fields (the client pays on the PayHub page)
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            Hosted sends the client to Worldpay's page. Card
                            fields shows Worldpay's card inputs on the PayHub
                            page. The mode cannot be changed once a payment uses
                            this account.
                        </p>
                        <InputError :message="form.errors.mode" />
                    </div>

                    <template v-if="form.mode === 'card_fields'">
                        <div class="grid gap-2">
                            <Label for="checkout_id">Checkout ID</Label>
                            <Input
                                id="checkout_id"
                                v-model="form.checkout_id"
                                type="text"
                                maxlength="64"
                                placeholder="e.g. 4c1b8b1e-3d0e-4d5b-9a53-2f4c6f0a1b11"
                            />
                            <p class="text-xs text-muted-foreground">
                                The Checkout ID comes from your Worldpay
                                implementation manager. Use the Try values for
                                test mode and the Live values for production.
                                Test connection cannot prove the Checkout ID or
                                its domain allow list. The first real pay page
                                load does, so open a Try payment link to check.
                            </p>
                            <InputError :message="form.errors.checkout_id" />
                        </div>

                        <div class="grid gap-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    id="google_pay_enabled"
                                    v-model="form.google_pay_enabled"
                                    type="checkbox"
                                    class="size-4"
                                />
                                Offer Google Pay (GBP payments only)
                            </label>
                            <InputError
                                :message="form.errors.google_pay_enabled"
                            />
                        </div>

                        <div v-if="form.google_pay_enabled" class="grid gap-2">
                            <Label for="google_pay_gateway_merchant_id">
                                Google Pay gateway merchant ID
                            </Label>
                            <Input
                                id="google_pay_gateway_merchant_id"
                                v-model="form.google_pay_gateway_merchant_id"
                                type="text"
                                maxlength="128"
                            />
                            <InputError
                                :message="
                                    form.errors.google_pay_gateway_merchant_id
                                "
                            />
                        </div>
                    </template>

                    <div class="grid gap-2">
                        <Label for="api_username">API username</Label>
                        <Input
                            id="api_username"
                            v-model="form.api_username"
                            type="password"
                            :placeholder="
                                worldpayAccount.has_api_username
                                    ? 'Saved'
                                    : 'Enter the API username'
                            "
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.api_username" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_password">API password</Label>
                        <Input
                            id="api_password"
                            v-model="form.api_password"
                            type="password"
                            :placeholder="
                                worldpayAccount.has_api_password
                                    ? 'Saved'
                                    : 'Enter the API password'
                            "
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.api_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="webhook_key_id">
                            Webhook key ID (optional)
                        </Label>
                        <Input
                            id="webhook_key_id"
                            v-model="form.webhook_key_id"
                            type="text"
                            maxlength="64"
                        />
                        <InputError :message="form.errors.webhook_key_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="webhook_secret">
                            Webhook secret (optional)
                        </Label>
                        <Input
                            id="webhook_secret"
                            v-model="form.webhook_secret"
                            type="password"
                            :placeholder="
                                worldpayAccount.has_webhook_secret
                                    ? 'Saved'
                                    : 'Enter the webhook secret'
                            "
                            autocomplete="new-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            Enter both the key ID and the secret, or neither.
                            Clearing the key ID removes the saved secret.
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
