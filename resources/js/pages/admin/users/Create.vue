<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eye, EyeOff, UserPlus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import MultiSelectCombobox from '@/components/MultiSelectCombobox.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type AccountOption = { id: number; account_name: string; provider: string };
type NamedOption = { id: number; name: string };
type PaymentAccountEntry = { currency: string; provider: string; account_id: string };

const props = defineProps<{
    roles: string[];
    accountsByCurrency: { usd: AccountOption[]; gbp: AccountOption[]; pkr: AccountOption[] };
    brands: NamedOption[];
    relationshipManagers: NamedOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
            { title: 'Add user', href: '/admin/users/create' },
        ],
    },
});

const form = useForm({
    name:                       '',
    username:                   '',
    password:                   '',
    role:                       'agent',
    payment_accounts:           [] as PaymentAccountEntry[],
    brand_ids:                  [] as number[],
    relationship_manager_ids:   [] as number[],
});

const providerLabel: Record<string, string> = { stripe: 'Stripe', revolut: 'Revolut', square: 'Square', viva: 'Viva', clover: 'Clover', mpgs: 'MPGS' };

// Each account selector encodes "provider:id" since ids collide across providers.
function toSelectOptions(accounts: AccountOption[]) {
    return accounts.map((a) => ({ id: `${a.provider}:${a.id}`, name: `${a.account_name} (${providerLabel[a.provider] ?? a.provider})` }));
}

const usdOptions = computed(() => toSelectOptions(props.accountsByCurrency.usd));
const gbpOptions = computed(() => toSelectOptions(props.accountsByCurrency.gbp));

const pkrOptions = computed(() => toSelectOptions(props.accountsByCurrency.pkr));

const usdAccountValue = ref('');
const gbpAccountValue = ref('');
const pkrAccountValue = ref('');

// Deterministic index into form.payment_accounts for each currency's field —
// entries are pushed in USD, GBP, PKR order, skipping any that are unset.
const usdEntryIndex = computed(() => (usdAccountValue.value ? 0 : -1));
const gbpEntryIndex = computed(() => (gbpAccountValue.value ? (usdAccountValue.value ? 1 : 0) : -1));
const pkrEntryIndex = computed(() => (pkrAccountValue.value ? [usdAccountValue.value, gbpAccountValue.value].filter(Boolean).length : -1));

watch([usdAccountValue, gbpAccountValue, pkrAccountValue], () => {
    const entries: PaymentAccountEntry[] = [];

    for (const [currency, value] of [['usd', usdAccountValue.value], ['gbp', gbpAccountValue.value], ['pkr', pkrAccountValue.value]]) {
        if (value) {
            const [provider, accountId] = value.split(':');
            entries.push({ currency, provider, account_id: accountId });
        }
    }

    form.payment_accounts = entries;
});

const showPassword = ref(false);

function submit() {
    form.post('/admin/users');
}
</script>

<template>
    <Head title="Add team member" />

    <div class="p-6">
        <!-- Page header -->
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2 mb-4">
                <Link href="/admin/users">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to users
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Add team member</CardTitle>
                <CardDescription>
                    Create a new account. The user can change their password after logging in.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="create-user-form" class="grid grid-cols-2 gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            autocomplete="name"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="username">Username</Label>
                        <Input
                            id="username"
                            v-model="form.username"
                            type="text"
                            autocomplete="username"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.username" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <div class="relative">
                            <Input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                class="pr-10"
                                required
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff v-if="showPassword" class="size-4" />
                                <Eye v-else class="size-4" />
                            </button>
                        </div>
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">Role</Label>
                        <Select v-model="form.role">
                            <SelectTrigger id="role" class="w-full">
                                <SelectValue placeholder="Select a role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Admin</SelectItem>
                                <SelectItem value="agent">Agent</SelectItem>
                                <SelectItem value="account">Account</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError class="mt-2" :message="form.errors.role" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label for="usd_account_id">USD Payment Account</Label>
                        <SearchableSelect
                            id="usd_account_id"
                            v-model="usdAccountValue"
                            :options="usdOptions"
                            placeholder="No USD account"
                            search-placeholder="Search accounts…"
                            empty-text="No active USD-capable accounts."
                        />
                        <InputError class="mt-2" :message="usdEntryIndex >= 0 ? form.errors[`payment_accounts.${usdEntryIndex}.account_id`] ?? form.errors[`payment_accounts.${usdEntryIndex}.currency`] : undefined" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label for="gbp_account_id">GBP Payment Account</Label>
                        <SearchableSelect
                            id="gbp_account_id"
                            v-model="gbpAccountValue"
                            :options="gbpOptions"
                            placeholder="No GBP account"
                            search-placeholder="Search accounts…"
                            empty-text="No active GBP-capable accounts."
                        />
                        <InputError class="mt-2" :message="gbpEntryIndex >= 0 ? form.errors[`payment_accounts.${gbpEntryIndex}.account_id`] ?? form.errors[`payment_accounts.${gbpEntryIndex}.currency`] : undefined" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label for="pkr_account_id">PKR Payment Account <span class="text-muted-foreground text-xs">(optional)</span></Label>
                        <SearchableSelect
                            id="pkr_account_id"
                            v-model="pkrAccountValue"
                            :options="pkrOptions"
                            placeholder="No PKR account (agent cannot take PKR)"
                            search-placeholder="Search accounts…"
                            empty-text="No active MPGS accounts with PKR enabled."
                        />
                        <InputError class="mt-2" :message="pkrEntryIndex >= 0 ? form.errors[`payment_accounts.${pkrEntryIndex}.account_id`] ?? form.errors[`payment_accounts.${pkrEntryIndex}.currency`] : undefined" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label>Brands <span class="text-destructive">*</span></Label>
                        <MultiSelectCombobox
                            v-model="form.brand_ids"
                            :options="brands"
                            placeholder="Select brands"
                            search-placeholder="Search brands…"
                            empty-text="No brands found."
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.brand_ids" />
                    </div>

                    <div v-if="form.role === 'agent'" class="grid gap-2">
                        <Label>Relationship Managers <span class="text-destructive">*</span></Label>
                        <MultiSelectCombobox
                            v-model="form.relationship_manager_ids"
                            :options="relationshipManagers"
                            placeholder="Select relationship managers"
                            search-placeholder="Search relationship managers…"
                            empty-text="No relationship managers found."
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.relationship_manager_ids" />
                    </div>
                </form>
            </CardContent>
            <CardFooter class="flex justify-end">
                <Button type="submit" form="create-user-form" :disabled="form.processing">
                    <UserPlus class="size-4 mr-1" />
                    Create user
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
