<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Pencil, Plus, Power, PowerOff, Search, Trash2, XCircle } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';
import { index as zelleAccountsIndex } from '@/actions/App/Http/Controllers/ZelleAccountController';
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
import { ZELLE_ACCOUNT_CURRENCIES, ZELLE_ACCOUNT_CURRENCY_LABELS } from '@/lib/zelle-account-currencies';
import ZelleDetailCard from './ZelleDetailCard.vue';

type ZelleAccountRow = {
    id: number;
    account_name: string;
    email: string;
    mobile_number: string | null;
    currency: string;
    is_active: boolean;
    assigned_users_count: number;
};

type MyZelleAccount = Omit<ZelleAccountRow, 'assigned_users_count' | 'is_active'>;

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    canManage: boolean;
    isAgent: boolean;
    zelleAccounts: Paginated<ZelleAccountRow> | null;
    myAccounts: MyZelleAccount[];
    filters: { search?: string | null; currency?: string | null; status?: string | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Zelle Accounts', href: '/zelle-accounts' },
        ],
    },
});

const filters = reactive({
    search: props.filters.search || '',
    currency: props.filters.currency || 'all',
    status: props.filters.status || 'all',
});

function buildQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (filters.search) {
        query.search = filters.search;
    }

    if (filters.currency !== 'all') {
        query.currency = filters.currency;
    }

    if (filters.status !== 'all') {
        query.status = filters.status;
    }

    return query;
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    filters,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            router.get(
                zelleAccountsIndex.url({ query: buildQuery() }),
                {},
                { preserveState: true, replace: true },
            );
        }, 250);
    },
    { deep: true },
);

const deactivateTarget = ref<ZelleAccountRow | null>(null);
const deactivateOpen   = ref(false);
const deactivateForm   = useForm({});

const activateTarget = ref<ZelleAccountRow | null>(null);
const activateOpen   = ref(false);
const activateForm   = useForm({});

const deleteTarget = ref<ZelleAccountRow | null>(null);
const deleteOpen   = ref(false);
const deleteForm   = useForm({});

function confirmActivate(account: ZelleAccountRow) {
    activateTarget.value = account;
    activateOpen.value   = true;
}

function executeActivate() {
    if (!activateTarget.value) {
        return;
    }

    activateForm.patch(`/zelle-accounts/${activateTarget.value.id}/activate`, {
        preserveScroll: true,
        onSuccess: () => {
            activateOpen.value   = false;
            activateTarget.value = null;
        },
    });
}

function confirmDeactivate(account: ZelleAccountRow) {
    deactivateTarget.value = account;
    deactivateOpen.value   = true;
}

function executeDeactivate() {
    if (!deactivateTarget.value) {
        return;
    }

    deactivateForm.patch(`/zelle-accounts/${deactivateTarget.value.id}/deactivate`, {
        preserveScroll: true,
        onSuccess: () => {
            deactivateOpen.value   = false;
            deactivateTarget.value = null;
        },
    });
}

function confirmDelete(account: ZelleAccountRow) {
    deleteTarget.value = account;
    deleteOpen.value   = true;
}

function executeDelete() {
    if (!deleteTarget.value) {
        return;
    }

    deleteForm.delete(`/zelle-accounts/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value   = false;
            deleteTarget.value = null;
        },
    });
}

const deleteDescription = (account: ZelleAccountRow | null): string => {
    const base = 'This account will be removed. This cannot be undone.';

    if (!account || account.assigned_users_count === 0) {
        return base;
    }

    return `${account.assigned_users_count} user(s) currently have access to this account and will lose it. ${base}`;
};
</script>

<template>
    <Head title="Zelle Accounts" />

    <div class="p-6 space-y-8">
        <template v-if="canManage && zelleAccounts">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">Zelle Accounts</h1>
                <Button as-child>
                    <Link href="/zelle-accounts/create">
                        <Plus class="size-4 mr-1" />
                        Add account
                    </Link>
                </Button>
            </div>

            <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
                <div class="flex flex-wrap items-end gap-4 p-4 border-b border-border/70">
                    <div class="flex flex-col gap-1.5 w-full max-w-xs">
                        <Label for="zelle-search" class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Search</Label>
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                id="zelle-search"
                                v-model="filters.search"
                                type="search"
                                placeholder="Email or mobile number…"
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
                                <SelectItem
                                    v-for="currency in ZELLE_ACCOUNT_CURRENCIES"
                                    :key="currency"
                                    :value="currency"
                                >
                                    {{ ZELLE_ACCOUNT_CURRENCY_LABELS[currency] }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="flex flex-col gap-1.5 w-full max-w-[10rem]">
                        <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</Label>
                        <Select v-model="filters.status">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="inactive">Inactive</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-[#F7F5F2] border-b border-border">
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Account Name</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Email</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Mobile</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Currency</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</th>
                            <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="account in zelleAccounts.data"
                            :key="account.id"
                            class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                        >
                            <td class="px-5 py-3.5 font-medium">{{ account.account_name }}</td>
                            <td class="px-5 py-3.5">{{ account.email }}</td>
                            <td class="px-5 py-3.5 text-muted-foreground">{{ account.mobile_number ?? '—' }}</td>
                            <td class="px-5 py-3.5 uppercase text-xs text-muted-foreground">{{ account.currency }}</td>
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
                                            :href="`/zelle-accounts/${account.id}/edit`"
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
                                        v-else
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

                        <tr v-if="zelleAccounts.data.length === 0">
                            <td colspan="6" class="px-5 py-16 text-center text-muted-foreground text-sm">
                                No Zelle accounts found.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-if="zelleAccounts.last_page > 1"
                    class="flex items-center justify-between border-t border-border/50 px-5 py-3.5 text-sm text-muted-foreground"
                >
                    <span>
                        Showing {{ zelleAccounts.from }}–{{ zelleAccounts.to }} of {{ zelleAccounts.total }}
                    </span>
                    <div class="flex items-center gap-2">
                        <Button v-if="zelleAccounts.prev_page_url" variant="outline" size="sm" as-child>
                            <Link :href="zelleAccounts.prev_page_url" preserve-scroll>Previous</Link>
                        </Button>
                        <Button v-else variant="outline" size="sm" disabled>Previous</Button>
                        <span class="tabular-nums">Page {{ zelleAccounts.current_page }} of {{ zelleAccounts.last_page }}</span>
                        <Button v-if="zelleAccounts.next_page_url" variant="outline" size="sm" as-child>
                            <Link :href="zelleAccounts.next_page_url" preserve-scroll>Next</Link>
                        </Button>
                        <Button v-else variant="outline" size="sm" disabled>Next</Button>
                    </div>
                </div>
            </div>
        </template>

        <div v-if="isAgent" class="space-y-4">
            <h2 class="text-lg font-semibold tracking-tight">My Zelle Accounts</h2>

            <div v-if="myAccounts.length === 0" class="rounded-xl border border-border/70 bg-card p-8 text-center text-sm text-muted-foreground">
                No Zelle accounts have been assigned to you yet.
            </div>

            <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <ZelleDetailCard v-for="account in myAccounts" :key="account.id" :account="account" />
            </div>
        </div>

        <div
            v-if="!canManage && !isAgent"
            class="rounded-xl border border-border/70 bg-card p-8 text-center text-sm text-muted-foreground"
        >
            No Zelle accounts are available to you.
        </div>
    </div>

    <Dialog v-model:open="deactivateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Deactivate account?</DialogTitle>
                <DialogDescription>
                    {{ deactivateTarget?.account_name }} will no longer appear in assigned users' "My Zelle Accounts" list.
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
