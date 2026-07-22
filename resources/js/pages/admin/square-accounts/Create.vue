<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Loader2, Plus, XCircle, Zap } from 'lucide-vue-next';
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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Square Accounts', href: '/admin/square-accounts' },
            { title: 'Add account', href: '#' },
        ],
    },
});

const form = useForm({
    account_name:           '',
    prefix:                 '',
    application_id:          '',
    location_id:            '',
    environment:            'sandbox',
    access_token:           '',
    webhook_signature_key:  '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');
const detectedCurrency = ref<string | null>(null);

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    detectedCurrency.value = null;
    router.post('/admin/square-accounts/test-connection',
        { access_token: form.access_token, environment: form.environment, location_id: form.location_id },
        {
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
        }
    );
}

function submit() {
    form.post('/admin/square-accounts');
}
</script>

<template>
    <Head title="Add Square account" />

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
                <CardTitle>Add Square account</CardTitle>
                <CardDescription>
                    Enter the application ID, location ID, and access token for this Square account.
                    The access token is stored encrypted and never displayed again after saving.
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
                            placeholder="e.g. Acme Corp UK"
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
                            placeholder="e.g. SPER"
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
                                <SelectItem value="sandbox">Sandbox</SelectItem>
                                <SelectItem value="production">Production</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.environment" />
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
                        <p class="text-xs text-muted-foreground">
                            Public — used by the Web Payments SDK on the payment page.
                        </p>
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
                        <Input
                            id="access_token"
                            v-model="form.access_token"
                            type="password"
                            placeholder="EAAA..."
                            autocomplete="new-password"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted. Never displayed after saving.
                        </p>
                        <InputError :message="form.errors.access_token" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="webhook_signature_key">Webhook signature key <span class="text-muted-foreground text-xs">(optional now — set after creating the webhook subscription)</span></Label>
                        <Input
                            id="webhook_signature_key"
                            v-model="form.webhook_signature_key"
                            type="password"
                            placeholder="Paste the signature key from the Square webhook subscription"
                            autocomplete="new-password"
                        />
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
                    :disabled="!form.access_token || !form.location_id || testStatus === 'testing'"
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
