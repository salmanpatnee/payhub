<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, ChevronDown, ChevronUp, Pencil, Plus, Power, PowerOff, Search, Trash2, XCircle } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';
import { index as bankAccountsIndex } from '@/actions/App/Http/Controllers/BankAccountController';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import { Button } from '@/components/ui/button';
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
import BankDetailCard from './BankDetailCard.vue';

type BankAccountRow = {
    id: number;
    bank_name: string;
    account_name: string;
    account_number: string;
    currency: string;
    sort_code: string | null;
    routing_number: string | null;
    iban: string | null;
    swift_bic: string | null;
    bank_address: string | null;
    bank_country: string | null;
    is_active: boolean;
    assigned_users_count: number;
};

type MyBankAccount = Omit<BankAccountRow, 'assigned_users_count'>;

type SortColumn = 'bank_name' | 'account_name';

const props = defineProps<{
    canManage: boolean;
    isAgent: boolean;
    bankAccounts: BankAccountRow[];
    myAccounts: MyBankAccount[];
    filters: { search?: string; currency?: string; sort?: SortColumn; direction?: 'asc' | 'desc' };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Bank Accounts', href: '/bank-accounts' },
        ],
    },
});

const filters = reactive({
    search: props.filters.search || '',
    currency: props.filters.currency || 'all',
    sort: props.filters.sort || 'bank_name',
    direction: props.filters.direction || 'asc',
});

function buildQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (filters.search) {
        query.search = filters.search;
    }

    if (filters.currency !== 'all') {
        query.currency = filters.currency;
    }

    if (filters.sort !== 'bank_name') {
        query.sort = filters.sort;
    }

    if (filters.direction !== 'asc') {
        query.direction = filters.direction;
    }

    return query;
}

watch(
    filters,
    () => {
        router.get(
            bankAccountsIndex.url({ query: buildQuery() }),
            {},
            { preserveState: true, replace: true },
        );
    },
    { deep: true },
);

function toggleSort(column: SortColumn): void {
    if (filters.sort === column) {
        filters.direction = filters.direction === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort = column;
        filters.direction = 'asc';
    }
}

const deactivateTarget = ref<BankAccountRow | null>(null);
const deactivateOpen   = ref(false);
const deactivateForm   = useForm({});

const activateTarget = ref<BankAccountRow | null>(null);
const activateOpen   = ref(false);
const activateForm   = useForm({});

const deleteTarget = ref<BankAccountRow | null>(null);
const deleteOpen   = ref(false);
const deleteForm   = useForm({});

function confirmActivate(account: BankAccountRow) {
    activateTarget.value = account;
    activateOpen.value   = true;
}

function executeActivate() {
    if (!activateTarget.value) {
        return;
    }

    activateForm.patch(`/bank-accounts/${activateTarget.value.id}/activate`, {
        onSuccess: () => {
            activateOpen.value   = false;
            activateTarget.value = null;
        },
    });
}

function confirmDeactivate(account: BankAccountRow) {
    deactivateTarget.value = account;
    deactivateOpen.value   = true;
}

function executeDeactivate() {
    if (!deactivateTarget.value) {
        return;
    }

    deactivateForm.patch(`/bank-accounts/${deactivateTarget.value.id}/deactivate`, {
        onSuccess: () => {
            deactivateOpen.value   = false;
            deactivateTarget.value = null;
        },
    });
}

function confirmDelete(account: BankAccountRow) {
    deleteTarget.value = account;
    deleteOpen.value   = true;
}

function executeDelete() {
    if (!deleteTarget.value) {
        return;
    }

    deleteForm.delete(`/bank-accounts/${deleteTarget.value.id}`, {
        onSuccess: () => {
            deleteOpen.value   = false;
            deleteTarget.value = null;
        },
    });
}

const deleteDescription = (account: BankAccountRow | null): string => {
    const base = 'This account will be permanently removed. This cannot be undone.';

    if (!account || account.assigned_users_count === 0) {
        return base;
    }

    return `${account.assigned_users_count} user(s) currently have access to this account and will lose it. ${base}`;
};
</script>

<template>
    <Head title="Bank Accounts" />

    <div class="p-6 space-y-8">
        <template v-if="canManage">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">Bank Accounts</h1>
                <Button as-child>
                    <Link href="/bank-accounts/create">
                        <Plus class="size-4 mr-1" />
                        Add account
                    </Link>
                </Button>
            </div>

            <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
                <div class="flex items-end gap-4 p-4 border-b border-border/70">
                    <div class="flex flex-col gap-1.5 w-full max-w-xs">
                        <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Search</Label>
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                v-model="filters.search"
                                type="search"
                                placeholder="Bank or account name…"
                                class="pl-8"
                            />
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5 w-full max-w-[10rem]">
                        <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Currency</Label>
                        <Select v-model="filters.currency">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="All currencies" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All currencies</SelectItem>
                                <SelectItem value="usd">USD</SelectItem>
                                <SelectItem value="gbp">GBP</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-[#F7F5F2] border-b border-border">
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">#</th>
                            <th
                                class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground cursor-pointer select-none"
                                @click="toggleSort('bank_name')"
                            >
                                <span class="inline-flex items-center gap-1">
                                    Bank
                                    <template v-if="filters.sort === 'bank_name'">
                                        <ChevronUp v-if="filters.direction === 'asc'" class="size-3.5" />
                                        <ChevronDown v-else class="size-3.5" />
                                    </template>
                                </span>
                            </th>
                            <th
                                class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground cursor-pointer select-none"
                                @click="toggleSort('account_name')"
                            >
                                <span class="inline-flex items-center gap-1">
                                    Account Name
                                    <template v-if="filters.sort === 'account_name'">
                                        <ChevronUp v-if="filters.direction === 'asc'" class="size-3.5" />
                                        <ChevronDown v-else class="size-3.5" />
                                    </template>
                                </span>
                            </th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Currency</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Country</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</th>
                            <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(account, index) in bankAccounts"
                            :key="account.id"
                            class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                        >
                            <td class="px-5 py-3.5 text-muted-foreground tabular-nums">{{ index + 1 }}</td>
                            <td class="px-5 py-3.5 font-medium">{{ account.bank_name }}</td>
                            <td class="px-5 py-3.5">{{ account.account_name }}</td>
                            <td class="px-5 py-3.5 uppercase text-xs text-muted-foreground">{{ account.currency }}</td>
                            <td class="px-5 py-3.5 text-muted-foreground">{{ account.bank_country ?? '—' }}</td>
                            <td class="px-5 py-3.5">
                                <div v-if="account.is_active" class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600 dark:text-green-500">
                                    <CheckCircle2 class="size-4" />
                                    Active
                                </div>
                                <div v-else class="inline-flex items-center gap-1.5 text-sm font-medium text-red-500 dark:text-red-400">
                                    <XCircle class="size-4" />
                                    Inactive
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <Button variant="ghost" size="icon" as-child>
                                        <Link
                                            :href="`/bank-accounts/${account.id}/edit`"
                                            :aria-label="`Edit ${account.account_name}`"
                                        >
                                            <Pencil class="size-4" />
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="account.is_active"
                                        variant="ghost"
                                        size="icon"
                                        class="cursor-pointer"
                                        :aria-label="`Deactivate ${account.account_name}`"
                                        @click="confirmDeactivate(account)"
                                    >
                                        <PowerOff class="size-4 text-destructive" />
                                    </Button>
                                    <Button
                                        v-if="!account.is_active"
                                        variant="ghost"
                                        size="icon"
                                        class="cursor-pointer"
                                        :aria-label="`Activate ${account.account_name}`"
                                        @click="confirmActivate(account)"
                                    >
                                        <Power class="size-4 text-green-600" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="cursor-pointer"
                                        :disabled="deleteForm.processing && deleteTarget?.id === account.id"
                                        :aria-label="`Delete ${account.account_name}`"
                                        @click="confirmDelete(account)"
                                    >
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="bankAccounts.length === 0">
                            <td colspan="7" class="px-5 py-16 text-center text-muted-foreground text-sm">
                                No bank accounts yet. Add an account to start sharing details with clients.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <div v-if="isAgent" class="space-y-4">
            <h2 class="text-lg font-semibold tracking-tight">My Bank Accounts</h2>

            <div v-if="myAccounts.length === 0" class="rounded-xl border border-border/70 bg-card p-8 text-center text-sm text-muted-foreground">
                No bank accounts have been assigned to you yet.
            </div>

            <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <BankDetailCard v-for="account in myAccounts" :key="account.id" :account="account" />
            </div>
        </div>
    </div>

    <Dialog v-model:open="deactivateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Deactivate account?</DialogTitle>
                <DialogDescription>
                    {{ deactivateTarget?.account_name }} will no longer appear in assigned users' "My Bank Accounts" list.
                    Existing assignments are kept.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deactivateOpen = false">Keep active</Button>
                <Button
                    variant="destructive"
                    :disabled="deactivateForm.processing"
                    @click="executeDeactivate"
                >
                    Deactivate
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="activateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Activate account?</DialogTitle>
                <DialogDescription>
                    {{ activateTarget?.account_name }} will become visible to assigned users again.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="activateOpen = false">Cancel</Button>
                <Button
                    :disabled="activateForm.processing"
                    @click="executeActivate"
                >
                    Activate
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDeleteDialog
        v-model:open="deleteOpen"
        :title="`Delete ${deleteTarget?.account_name ?? 'account'}?`"
        :description="deleteDescription(deleteTarget)"
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
