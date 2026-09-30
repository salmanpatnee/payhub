<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Banknote,
    CalendarClock,
    CheckCircle2,
    CircleAlert,
    CircleHelp,
    CircleX,
    Clock,
    CreditCard,
    Hourglass,
    Info,
    Landmark,
    RefreshCw,
    XCircle,
} from 'lucide-vue-next';
import { computed } from 'vue';
import type { Component } from 'vue';
import StripeHealthBadge from '@/components/StripeHealthBadge.vue';
import { Button } from '@/components/ui/button';
import { healthStatus } from '@/lib/stripeHealth';
import type { HealthStatusKey } from '@/lib/stripeHealth';

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

const props = defineProps<{ accounts: Account[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Account Health', href: '/stripe-health' }],
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

function deadline(ts: number | null): string | null {
    return ts ? new Date(ts * 1000).toLocaleDateString() : null;
}

const requirementGroups: {
    key: 'past_due' | 'currently_due' | 'eventually_due' | 'pending_verification';
    label: string;
    icon: Component;
    text: string;
    chip: string;
}[] = [
    {
        key: 'past_due',
        label: 'Overdue',
        icon: CircleX,
        text: 'text-red-600 dark:text-red-400',
        chip: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
    },
    {
        key: 'currently_due',
        label: 'Due now',
        icon: CircleAlert,
        text: 'text-amber-600 dark:text-amber-400',
        chip: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
    },
    {
        key: 'eventually_due',
        label: 'Due later',
        icon: CalendarClock,
        text: 'text-sky-600 dark:text-sky-400',
        chip: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900',
    },
    {
        key: 'pending_verification',
        label: 'Pending verification',
        icon: Hourglass,
        text: 'text-zinc-500 dark:text-zinc-400',
        chip: 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-zinc-800/50 dark:text-zinc-300 dark:ring-zinc-700',
    },
];

function capabilities(health: Health) {
    return [
        { label: 'Charges', icon: CreditCard, on: health.charges_enabled },
        { label: 'Payouts', icon: Banknote, on: health.payouts_enabled },
    ];
}

const summary = computed(() => {
    const order: HealthStatusKey[] = ['healthy', 'needs_attention', 'restricted', 'unreachable'];

    return order
        .map((key) => ({
            key,
            style: healthStatus[key],
            count: props.accounts.filter((a) => (a.health?.status ?? 'none') === key).length,
        }));
});
</script>

<template>
    <Head title="Account Health" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Account Health</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Live status of every connected Stripe account. Checked automatically every 15 minutes.
                </p>
            </div>
            <Button class="cursor-pointer" :disabled="checkAllForm.processing" @click="checkAll">
                <RefreshCw :class="['size-4 mr-1', checkAllForm.processing && 'animate-spin']" />
                Check all now
            </Button>
        </div>

        <div class="grid grid-cols-2 divide-x divide-y divide-border overflow-hidden rounded-lg border border-border bg-card shadow-sm md:grid-cols-4 md:divide-y-0">
            <div v-for="s in summary" :key="s.key" class="px-5 py-4">
                <p class="flex items-center gap-2 text-sm text-muted-foreground">
                    <span :class="['size-2 rounded-full', s.style.dot]" />
                    {{ s.style.label }}
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight">{{ s.count }}</p>
            </div>
        </div>

        <div v-if="accounts.length === 0" class="rounded-lg border border-border bg-card px-5 py-16 text-center text-sm text-muted-foreground shadow-sm">
            No Stripe accounts yet.
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div
                v-for="account in accounts"
                :key="account.id"
                class="overflow-hidden rounded-lg border border-border bg-card shadow-sm"
            >
                <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted/50 text-muted-foreground">
                            <Landmark class="size-4.5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h2 class="truncate text-sm font-semibold">{{ account.account_name }}</h2>
                                <span
                                    v-if="account.prefix"
                                    class="rounded border border-border bg-muted/50 px-1.5 py-px font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ account.prefix }}
                                </span>
                            </div>
                            <p
                                class="mt-0.5 flex items-center gap-1 text-xs"
                                :class="account.is_active ? 'text-muted-foreground' : 'text-red-500 dark:text-red-400'"
                            >
                                <CheckCircle2 v-if="account.is_active" class="size-3.5 text-emerald-500" />
                                <XCircle v-else class="size-3.5" />
                                {{ account.is_active ? 'Active in PayHub' : 'Inactive in PayHub' }}
                            </p>
                        </div>
                    </div>
                    <StripeHealthBadge :status="account.health?.status ?? null" />
                </div>

                <div class="space-y-4 px-5 py-4">
                    <template v-if="account.health">
                        <dl class="divide-y divide-border/70 text-sm">
                            <div
                                v-for="cap in capabilities(account.health)"
                                :key="cap.label"
                                class="flex items-center justify-between py-2.5 first:pt-0"
                            >
                                <dt class="flex items-center gap-2 text-muted-foreground">
                                    <component :is="cap.icon" class="size-4" />
                                    {{ cap.label }}
                                </dt>
                                <dd>
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                            cap.on === true && 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
                                            cap.on === false && 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
                                            cap.on === null && 'bg-muted text-muted-foreground ring-border',
                                        ]"
                                    >
                                        <CheckCircle2 v-if="cap.on === true" class="size-3.5" />
                                        <XCircle v-else-if="cap.on === false" class="size-3.5" />
                                        <CircleHelp v-else class="size-3.5" />
                                        {{ cap.on === null ? 'Unknown' : cap.on ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </dd>
                            </div>
                            <div v-if="account.health.disabled_reason" class="flex items-center justify-between py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground">
                                    <CircleAlert class="size-4" />
                                    Stripe's reason
                                </dt>
                                <dd class="font-mono text-xs text-red-600 dark:text-red-400">{{ account.health.disabled_reason }}</dd>
                            </div>
                            <div v-if="deadline(account.health.requirements?.current_deadline ?? null)" class="flex items-center justify-between py-2.5">
                                <dt class="flex items-center gap-2 text-muted-foreground">
                                    <CalendarClock class="size-4" />
                                    Deadline
                                </dt>
                                <dd class="font-medium text-amber-600 dark:text-amber-400">
                                    {{ deadline(account.health.requirements?.current_deadline ?? null) }}
                                </dd>
                            </div>
                        </dl>

                        <template v-if="account.health.requirements">
                            <template v-for="group in requirementGroups" :key="group.key">
                                <div v-if="account.health.requirements[group.key]?.length" class="space-y-1.5">
                                    <p :class="['flex items-center gap-1.5 text-xs font-semibold', group.text]">
                                        <component :is="group.icon" class="size-3.5" />
                                        {{ group.label }}
                                    </p>
                                    <ul class="flex flex-wrap gap-1.5">
                                        <li
                                            v-for="item in account.health.requirements[group.key]"
                                            :key="item"
                                            :class="['rounded-md px-2 py-0.5 font-mono text-xs ring-1 ring-inset', group.chip]"
                                        >
                                            {{ item }}
                                        </li>
                                    </ul>
                                </div>
                            </template>
                        </template>

                        <div
                            v-if="account.health.last_error"
                            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2.5 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-400"
                        >
                            <CircleX class="mt-0.5 size-4 shrink-0" />
                            <span>{{ account.health.last_error }}</span>
                        </div>

                        <div
                            v-if="account.health.status === 'restricted' && !account.health.disabled_reason"
                            class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                        >
                            <Info class="mt-0.5 size-4 shrink-0" />
                            <span>Stripe did not say why. Log in to Stripe to see the reason.</span>
                        </div>
                    </template>
                    <div
                        v-else
                        class="flex items-center gap-2 rounded-md border border-dashed border-border px-3 py-3 text-sm text-muted-foreground"
                    >
                        <CircleHelp class="size-4 shrink-0" />
                        This account has not been checked yet. Press Check now.
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-border bg-muted/30 px-5 py-3">
                    <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Clock class="size-3.5" />
                        Last checked {{ account.health ? ago(account.health.last_checked_at) : 'never' }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        class="cursor-pointer bg-card"
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
