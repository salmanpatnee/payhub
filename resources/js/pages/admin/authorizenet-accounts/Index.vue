<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Pencil, Plus, Power, PowerOff, Trash2, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';
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

type AuthorizenetAccountRow = {
    id: number;
    account_name: string;
    prefix: string | null;
    api_login_id: string;
    environment: 'sandbox' | 'production';
    currency: string;
    has_signature_key: boolean;
    is_active: boolean;
};

defineProps<{ authorizenetAccounts: AuthorizenetAccountRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Authorize.net Accounts', href: '/admin/authorizenet-accounts' },
        ],
    },
});

const deactivateTarget = ref<AuthorizenetAccountRow | null>(null);
const deactivateOpen   = ref(false);
const deactivateForm   = useForm({});

const activateTarget = ref<AuthorizenetAccountRow | null>(null);
const activateOpen   = ref(false);
const activateForm   = useForm({});

const deleteTarget = ref<AuthorizenetAccountRow | null>(null);
const deleteOpen   = ref(false);
const deleteForm   = useForm({});

function confirmActivate(account: AuthorizenetAccountRow) {
    activateTarget.value = account;
    activateOpen.value   = true;
}

function executeActivate() {
    if (!activateTarget.value) {
return;
}

    activateForm.patch(`/admin/authorizenet-accounts/${activateTarget.value.id}/activate`, {
        onSuccess: () => {
            activateOpen.value   = false;
            activateTarget.value = null;
        },
    });
}

function confirmDeactivate(account: AuthorizenetAccountRow) {
    deactivateTarget.value = account;
    deactivateOpen.value   = true;
}

function executeDeactivate() {
    if (!deactivateTarget.value) {
return;
}

    deactivateForm.patch(`/admin/authorizenet-accounts/${deactivateTarget.value.id}/deactivate`, {
        onSuccess: () => {
            deactivateOpen.value   = false;
            deactivateTarget.value = null;
        },
    });
}

function confirmDelete(account: AuthorizenetAccountRow) {
    deleteTarget.value = account;
    deleteOpen.value   = true;
}

function executeDelete() {
    if (!deleteTarget.value) {
return;
}

    deleteForm.delete(`/admin/authorizenet-accounts/${deleteTarget.value.id}`, {
        onSuccess: () => {
            deleteOpen.value   = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="Authorize.net Accounts" />

    <div class="p-6 space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Authorize.net Accounts</h1>
            </div>
            <Button as-child>
                <Link href="/admin/authorizenet-accounts/create">
                    <Plus class="size-4 mr-1" />
                    Add account
                </Link>
            </Button>
        </div>

        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-[#F7F5F2] border-b border-border">
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">#</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Account Name</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Prefix</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Environment</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</th>
                        <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(account, index) in authorizenetAccounts"
                        :key="account.id"
                        class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                    >
                        <td class="px-5 py-3.5 text-muted-foreground tabular-nums">{{ index + 1 }}</td>
                        <td class="px-5 py-3.5 font-medium">{{ account.account_name }}</td>
                        <td class="px-5 py-3.5 font-mono text-xs text-muted-foreground">{{ account.prefix ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-xs capitalize text-muted-foreground">{{ account.environment }}</td>
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
                                        :href="`/admin/authorizenet-accounts/${account.id}/edit`"
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

                    <tr v-if="authorizenetAccounts.length === 0">
                        <td colspan="6" class="px-5 py-16 text-center text-muted-foreground text-sm">
                            No Authorize.net accounts yet. Add an account to enable card collection through Authorize.net.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Dialog v-model:open="deactivateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Deactivate account?</DialogTitle>
                <DialogDescription>
                    {{ deactivateTarget?.account_name }} will no longer be available for new payments.
                    Existing payment links remain active until paid.
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
                    {{ activateTarget?.account_name }} will become available for new payments.
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
        description="This account will be permanently removed. This cannot be undone."
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
