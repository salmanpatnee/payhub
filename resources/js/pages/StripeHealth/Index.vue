<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, RefreshCw, XCircle } from 'lucide-vue-next';
import StripeHealthBadge from '@/components/StripeHealthBadge.vue';
import { Button } from '@/components/ui/button';

type Requirements = {
    currently_due: string[];
    past_due: string[];
    eventually_due: string[];
    pending_verification: string[];
    current_deadline: number | null;
} | null;

type Health = {
    status: string;
    charges_enabled: boolean | null;
    payouts_enabled: boolean | null;
    details_submitted: boolean | null;
    disabled_reason: string | null;
    requirements: Requirements;
    country: string | null;
    default_currency: string | null;
    last_error: string | null;
    status_changed_at: string | null;
    last_checked_at: string | null;
};

type Account = {
    id: number;
    account_name: string;
    prefix: string | null;
    is_active: boolean;
    health: Health | null;
    performance: {
        completed_7d: number;
        failed_7d: number;
        completed_30d: number;
        failed_30d: number;
        stuck_pending: number;
    };
};

defineProps<{ accounts: Account[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Stripe Health', href: '/stripe-health' }],
    },
});

const checkAllForm = useForm({});
const checkForm = useForm({});

function checkAll() {
    checkAllForm.post('/stripe-health/check', { preserveScroll: true });
}

function checkOne(id: number) {
    checkForm.post(`/stripe-health/${id}/check`, { preserveScroll: true });
}

function rate(completed: number, failed: number): string {
    const total = completed + failed;

    if (total === 0) {
return 'No data';
}

    return `${Math.round((completed / total) * 100)}%`;
}

function ago(iso: string | null): string {
    if (!iso) {
return '—';
}

    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 60) {
return 'just now';
}

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
return `${minutes} min ago`;
}

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
return `${hours} h ago`;
}

    return `${Math.floor(hours / 24)} d ago`;
}

function since(iso: string | null): string {
    const text = ago(iso);

    return text === 'just now' || text === '—' ? text : text.replace(' ago', '');
}

function deadline(ts: number | null): string | null {
    return ts ? new Date(ts * 1000).toLocaleDateString() : null;
}

const requirementGroups: { key: 'past_due' | 'currently_due' | 'eventually_due' | 'pending_verification'; label: string }[] = [
    { key: 'past_due', label: 'Overdue' },
    { key: 'currently_due', label: 'Due now' },
    { key: 'eventually_due', label: 'Due later' },
    { key: 'pending_verification', label: 'Pending verification' },
];
</script>

<template>
    <Head title="Stripe Health" />

    <div class="p-6 space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight">Stripe Health</h1>
            <Button class="cursor-pointer" :disabled="checkAllForm.processing" @click="checkAll">
                <RefreshCw :class="['size-4 mr-1', checkAllForm.processing && 'animate-spin']" />
                Check all now
            </Button>
        </div>

        <div v-if="accounts.length === 0" class="rounded-xl border border-border/70 bg-card px-5 py-16 text-center text-sm text-muted-foreground">
            No Stripe accounts yet.
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div
                v-for="account in accounts"
                :key="account.id"
                class="rounded-xl border border-border/70 bg-card shadow-sm p-5 space-y-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <h2 class="font-semibold">{{ account.account_name }}</h2>
                            <span v-if="account.prefix" class="font-mono text-xs text-muted-foreground">{{ account.prefix }}</span>
                        </div>
                        <div
                            class="inline-flex items-center gap-1 text-xs font-medium"
                            :class="account.is_active ? 'text-green-600 dark:text-green-500' : 'text-red-500 dark:text-red-400'"
                        >
                            <CheckCircle2 v-if="account.is_active" class="size-3.5" />
                            <XCircle v-else class="size-3.5" />
                            {{ account.is_active ? 'Active' : 'Inactive' }}
                        </div>
                    </div>
                    <StripeHealthBadge :status="account.health?.status ?? null" />
                </div>

                <template v-if="account.health">
                    <div class="flex gap-6 text-sm">
                        <div>
                            <span class="text-muted-foreground">Charges</span>
                            <span class="ml-1.5 font-medium">{{ account.health.charges_enabled === null ? '—' : account.health.charges_enabled ? 'On' : 'Off' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Payouts</span>
                            <span class="ml-1.5 font-medium">{{ account.health.payouts_enabled === null ? '—' : account.health.payouts_enabled ? 'On' : 'Off' }}</span>
                        </div>
                    </div>

                    <p v-if="account.health.disabled_reason" class="text-sm">
                        <span class="text-muted-foreground">Stripe's reason:</span>
                        <span class="ml-1.5 font-mono text-xs">{{ account.health.disabled_reason }}</span>
                    </p>

                    <template v-if="account.health.requirements">
                        <div v-for="group in requirementGroups" :key="group.key">
                            <template v-if="account.health.requirements[group.key]?.length">
                                <p class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">{{ group.label }}</p>
                                <ul class="mt-1 flex flex-wrap gap-1.5">
                                    <li
                                        v-for="item in account.health.requirements[group.key]"
                                        :key="item"
                                        class="rounded-md bg-muted px-2 py-0.5 font-mono text-xs"
                                    >
                                        {{ item }}
                                    </li>
                                </ul>
                            </template>
                        </div>
                        <p v-if="deadline(account.health.requirements.current_deadline)" class="text-sm">
                            <span class="text-muted-foreground">Deadline:</span>
                            <span class="ml-1.5 font-medium">{{ deadline(account.health.requirements.current_deadline) }}</span>
                        </p>
                    </template>

                    <p
                        v-if="account.health.last_error"
                        class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-400"
                    >
                        {{ account.health.last_error }}
                    </p>

                    <p
                        v-if="account.health.status === 'restricted' && !account.health.disabled_reason"
                        class="text-xs text-muted-foreground"
                    >
                        Stripe did not say why. Log in to Stripe to see the reason.
                    </p>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    This account has not been checked yet. Press Check now.
                </p>

                <dl class="grid grid-cols-2 gap-3 border-t border-border/60 pt-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-muted-foreground">Success, 7 days</dt>
                        <dd class="font-medium tabular-nums">
                            {{ rate(account.performance.completed_7d, account.performance.failed_7d) }}
                            <span class="text-xs font-normal text-muted-foreground">({{ account.performance.completed_7d }}/{{ account.performance.completed_7d + account.performance.failed_7d }})</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Success, 30 days</dt>
                        <dd class="font-medium tabular-nums">
                            {{ rate(account.performance.completed_30d, account.performance.failed_30d) }}
                            <span class="text-xs font-normal text-muted-foreground">({{ account.performance.completed_30d }}/{{ account.performance.completed_30d + account.performance.failed_30d }})</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Pending over 24 h</dt>
                        <dd class="font-medium tabular-nums">{{ account.performance.stuck_pending }}</dd>
                    </div>
                    <div v-if="account.health">
                        <dt class="text-xs text-muted-foreground">In this status</dt>
                        <dd class="font-medium">{{ since(account.health.status_changed_at) }}</dd>
                    </div>
                </dl>

                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">
                        Last checked: {{ account.health ? ago(account.health.last_checked_at) : 'never' }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        class="cursor-pointer"
                        :disabled="checkForm.processing"
                        @click="checkOne(account.id)"
                    >
                        <RefreshCw class="size-3.5 mr-1" />
                        Check now
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
