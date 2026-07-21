<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type PaymentAccountSummary = { currency: string; account_name: string | null };
type UserRow = {
    id: number;
    name: string;
    username: string;
    roles: string[];
    payment_accounts: PaymentAccountSummary[];
};

defineProps<{ users: UserRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
        ],
    },
});

const currencyLabel: Record<string, string> = { usd: 'USD', gbp: 'GBP' };

function paymentAccountsSummary(user: UserRow): string {
    return user.payment_accounts
        .map((a) => `${currencyLabel[a.currency] ?? a.currency.toUpperCase()}: ${a.account_name ?? 'Inactive account'}`)
        .join(' · ');
}

const page         = usePage();
const authUserId   = computed(() => page.props.auth.user?.id);
const deleteTarget = ref<UserRow | null>(null);
const deleteOpen   = ref(false);

const deleteForm = useForm({});

function confirmDelete(user: UserRow) {
    deleteTarget.value = user;
    deleteOpen.value   = true;
}

function executeDelete() {
    if (!deleteTarget.value) {
        return;
    }

    deleteForm.delete(`/admin/users/${deleteTarget.value.id}`, {
        onSuccess: () => {
            deleteOpen.value   = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="Team Members" />

    <div class="p-6 space-y-6">
        <!-- Page header -->
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight">Team Members</h1>
            <Button as-child>
                <Link href="/admin/users/create">Add user</Link>
            </Button>
        </div>

        <!-- User table -->
        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-[#F7F5F2] border-b border-border">
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Name</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Username</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Role</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Payment Account</th>
                        <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="users.length > 0">
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                        >
                            <td class="px-5 py-3.5">{{ user.name }}</td>
                            <td class="px-5 py-3.5 text-muted-foreground">{{ user.username }}</td>
                            <td class="px-5 py-3.5">
                                <Badge :variant="user.roles.includes('admin') ? 'secondary' : 'outline'" class="capitalize">
                                    {{ user.roles[0] ?? 'agent' }}
                                </Badge>
                            </td>
                            <td class="px-5 py-3.5 text-muted-foreground">
                                <span
                                    v-if="user.roles.includes('agent') && user.payment_accounts.length === 0"
                                    class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400"
                                >
                                    Not configured
                                </span>
                                <span v-else-if="user.payment_accounts.length > 0">{{ paymentAccountsSummary(user) }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <Button variant="ghost" size="icon" as-child>
                                        <Link :href="`/admin/users/${user.id}/edit`" :aria-label="`Edit ${user.name}`">
                                            <Pencil class="size-4" />
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="user.id !== authUserId"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Delete ${user.name}`"
                                        @click="confirmDelete(user)"
                                    >
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <template v-else>
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center text-muted-foreground text-sm">
                                No team members yet. Add the first user above.
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Delete confirmation dialog -->
    <Dialog v-model:open="deleteOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete user?</DialogTitle>
                <DialogDescription>
                    This will permanently remove {{ deleteTarget?.name }}'s account. This action cannot be undone.
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
