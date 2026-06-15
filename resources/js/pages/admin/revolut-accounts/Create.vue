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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Revolut Accounts', href: '/admin/revolut-accounts' },
            { title: 'Add account', href: '#' },
        ],
    },
});

const form = useForm({
    account_name: '',
    public_key:   '',
    secret_key:   '',
});

const testStatus = ref<'idle' | 'testing' | 'ok' | 'fail'>('idle');
const testMessage = ref('');

function testConnection() {
    testStatus.value = 'testing';
    testMessage.value = '';
    router.post('/admin/revolut-accounts/test-connection',
        { secret_key: form.secret_key },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => { testStatus.value = 'ok'; testMessage.value = 'Connected successfully.'; },
            onError: (errors) => {
                const e = errors as Record<string, string>;
                testStatus.value = 'fail';
                testMessage.value = e.revolut_api ?? e.secret_key ?? 'Connection test failed.';
            },
        }
    );
}

function submit() {
    form.post('/admin/revolut-accounts');
}
</script>

<template>
    <Head title="Add Revolut account" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/revolut-accounts">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to accounts
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add Revolut account</CardTitle>
                <CardDescription>
                    Enter the Merchant API secret key for this Revolut account.
                    The secret key is stored encrypted and never displayed again after saving.
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
                            placeholder="e.g. Acme Corp Live"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            A label to identify this account in the admin panel.
                        </p>
                        <InputError :message="form.errors.account_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="public_key">Public key <span class="text-muted-foreground">(optional)</span></Label>
                        <Input
                            id="public_key"
                            v-model="form.public_key"
                            type="text"
                            placeholder="pk_..."
                            autocomplete="off"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional. The embedded card field is driven by the per-order token.
                        </p>
                        <InputError :message="form.errors.public_key" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="secret_key">Secret key</Label>
                        <Input
                            id="secret_key"
                            v-model="form.secret_key"
                            type="password"
                            placeholder="sk_..."
                            autocomplete="new-password"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted. Never displayed after saving.
                        </p>
                        <InputError :message="form.errors.secret_key" />
                    </div>

                    <div
                        v-if="testStatus !== 'idle'"
                        class="flex items-center gap-2 text-sm rounded-md px-3 py-2"
                        :class="testStatus === 'ok' ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400' : testStatus === 'fail' ? 'bg-destructive/10 text-destructive' : 'text-muted-foreground'"
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
                    :disabled="!form.secret_key || testStatus === 'testing'"
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
