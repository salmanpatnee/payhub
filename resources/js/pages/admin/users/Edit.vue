<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Check, Eye, EyeOff } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import MultiSelectCombobox from '@/components/MultiSelectCombobox.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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

type UserProp = {
    id: number;
    name: string;
    username: string;
    roles: string[];
    payment_accounts: { currency: string; provider: string; account_id: number }[];
    brand_ids: number[];
    relationship_manager_ids: number[];
};

const props = defineProps<{
    user: UserProp;
    roles: string[];
    accountsByCurrency: { usd: AccountOption[]; gbp: AccountOption[] };
    brands: NamedOption[];
    relationshipManagers: NamedOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
            { title: 'Edit user', href: '#' },
        ],
    },
});

const page     = usePage();
const isSelf   = computed(() => page.props.auth.user?.id === props.user.id);
const deleteOpen = ref(false);
const showPassword = ref(false);

const form = useForm({
    name:                       props.user.name,
    username:                   props.user.username,
    password:                   '',
    role:                       props.user.roles[0] ?? 'agent',
    payment_accounts:           props.user.payment_accounts.map((a) => ({ ...a, account_id: String(a.account_id) })) as PaymentAccountEntry[],
    brand_ids:                  [...props.user.brand_ids],
    relationship_manager_ids:   [...props.user.relationship_manager_ids],
});

const providerLabel: Record<string, string> = { stripe: 'Stripe', revolut: 'Revolut', square: 'Square', viva: 'Viva' };

// Each account selector encodes "provider:id" since ids collide across providers.
function toSelectOptions(accounts: AccountOption[]) {
    return accounts.map((a) => ({ id: `${a.provider}:${a.id}`, name: `${a.account_name} (${providerLabel[a.provider] ?? a.provider})` }));
}

const usdOptions = computed(() => toSelectOptions(props.accountsByCurrency.usd));
const gbpOptions = computed(() => toSelectOptions(props.accountsByCurrency.gbp));

const existingUsd = props.user.payment_accounts.find((a) => a.currency === 'usd');
const existingGbp = props.user.payment_accounts.find((a) => a.currency === 'gbp');

const usdAccountValue = ref(existingUsd ? `${existingUsd.provider}:${existingUsd.account_id}` : '');
const gbpAccountValue = ref(existingGbp ? `${existingGbp.provider}:${existingGbp.account_id}` : '');

// Deterministic index into form.payment_accounts for each currency's field —
// USD (if set) always lands first, GBP fills whichever slot remains.
const usdEntryIndex = computed(() => (usdAccountValue.value ? 0 : -1));
const gbpEntryIndex = computed(() => (gbpAccountValue.value ? (usdAccountValue.value ? 1 : 0) : -1));

watch([usdAccountValue, gbpAccountValue], () => {
    const entries: PaymentAccountEntry[] = [];

    if (usdAccountValue.value) {
        const [provider, accountId] = usdAccountValue.value.split(':');
        entries.push({ currency: 'usd', provider, account_id: accountId });
    }

    if (gbpAccountValue.value) {
        const [provider, accountId] = gbpAccountValue.value.split(':');
        entries.push({ currency: 'gbp', provider, account_id: accountId });
    }

    form.payment_accounts = entries;
});

const deleteForm = useForm({});

function submit() {
    form.patch(`/admin/users/${props.user.id}`);
}

function executeDelete() {
    deleteForm.delete(`/admin/users/${props.user.id}`, {
        onSuccess: () => {
            deleteOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <div class="p-6">
        <!-- Back navigation -->
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/users">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to users
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Edit team member</CardTitle>
                <CardDescription>
                    Update name, username, role, or password for this account.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form id="edit-user-form" class="grid grid-cols-2 gap-4" @submit.prevent="submit">
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
                                placeholder="Leave blank to keep current password"
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
            <CardFooter class="flex items-center justify-between">
                <Button
                    v-if="!isSelf"
                    variant="destructive"
                    type="button"
                    @click="deleteOpen = true"
                >
                    Delete user
                </Button>
                <div v-else />
                <Button type="submit" form="edit-user-form" :disabled="form.processing">
                    <Check class="size-4 mr-1" />
                    Save changes
                </Button>
            </CardFooter>
        </Card>
    </div>

    <!-- Delete confirmation dialog -->
    <Dialog v-model:open="deleteOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete user?</DialogTitle>
                <DialogDescription>
                    This will permanently remove {{ user.name }}'s account. This action cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deleteOpen = false">Keep account</Button>
                <Button
                    variant="destructive"
                    :disabled="deleteForm.processing"
                    @click="executeDelete"
                >
                    Delete user
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
