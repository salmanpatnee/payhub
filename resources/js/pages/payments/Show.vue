<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, type Component } from 'vue';
import {
    ArrowLeft, Ban, Check, CheckCircle2,
    Clock4, Copy, Link2, LockKeyhole, LockOpen, Pencil, Plus, Trash2, XCircle,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import PaymentProviderBadge from '@/components/PaymentProviderBadge.vue';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import { withBase } from '@/lib/basePath';
import { Card } from '@/components/ui/card';

type PaymentDetail = {
    uuid: string;
    reference_code: string | null;
    reference_slug: string | null;
    amount: number;
    currency: string;
    status: string;
    client_name: string;
    client_email: string;
    service: string | null;
    package: string | null;
    note: string | null;
    brand_name: string;
    account_name: string | null;
    provider: string;
    provider_label: string;
    relationship_manager_name: string | null;
    created_at: string;
    provider_reference: string | null;
    paid_at: string | null;
    expires_at: string | null;
    /** Admin only: when the card attempt lock ends, or null when not locked. */
    card_locked_until: string | null;
};

type AttemptRow = { sequence: number; reference: string | null; status: string; reason: string | null; started_at: string };

// Authorize.net payments made before attempts were stored have no rows, only the summary.
type AttemptHistory =
    | { provider: 'mpgs'; rows: AttemptRow[] }
    | {
        provider: 'authorizenet';
        rows: AttemptRow[];
        summary: { first_attempt_at: string | null; last_attempt_at: string | null; transaction_id: string | null; status: string };
    };

const props = defineProps<{
    payment: PaymentDetail;
    isAdmin: boolean;
    canViewStripeAccount: boolean;
    attempts: AttemptHistory | null;
}>();

// Attempt statuses reuse the payment badge tones.
const attemptTone: Record<string, string> = {
    approved: 'completed',
    declined: 'failed',
    hard_error: 'failed',
    prepared: 'pending',
    pending: 'pending',
    abandoned: 'cancelled',
    // Authorize.net
    sent: 'pending',
    held: 'pending',
    unclear: 'pending',
    check_details: 'failed',
    error: 'failed',
};

const deleteOpen = ref(false);
const deleteForm = useForm({});

const releasing = ref(false);

function releaseLock() {
    releasing.value = true;
    router.patch(`/payments/${props.payment.uuid}/reset-lock`, {}, {
        preserveScroll: true,
        onFinish: () => {
            releasing.value = false;
        },
    });
}

function executeDelete() {
    deleteForm.delete(`/payments/${props.payment.uuid}`, {
        onSuccess: () => { deleteOpen.value = false; },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'Payment link', href: '#' },
        ],
    },
});

// /pay/{uuid} is the client-facing route (Phase 5). Show page is /payments/{uuid} (auth-only).
const shareableLink = `${window.location.origin}${withBase(`/pay/${props.payment.uuid}${props.payment.reference_slug ? `/${props.payment.reference_slug}` : ''}`)}`;

const copied = ref(false);

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(shareableLink);
    } catch {
        const el = document.createElement('textarea');
        el.value = shareableLink;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}

function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : currency === 'pkr' ? 'en-PK' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100);
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function titleCase(str: string | null): string {
    if (!str) return '—';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

const referenceLabel = computed(() => props.payment.reference_code ?? 'NO REFERENCE');

const providerReferenceLabels: Record<string, string> = {
    stripe: 'Stripe PI',
    revolut: 'Revolut Order',
    square: 'Square Payment ID',
    viva: 'Viva Order/Transaction ID',
    clover: 'Clover Payment ID',
    mpgs: 'Online Bank Order ID',
    authorizenet: 'Authorize.net Transaction ID',
};

// Unknown providers fall back to Stripe's label, as the old inline ternary did.
// MPGS is shown to staff as "Online Bank" on this page only.
const providerName = computed(() => (props.payment.provider === 'mpgs' ? 'Online Bank' : props.payment.provider_label));

const providerReferenceLabel = computed(() => providerReferenceLabels[props.payment.provider] ?? providerReferenceLabels.stripe);

type Tone = 'done' | 'pending' | 'failed' | 'muted';
type TimelineStep = { label: string; date: string; tone: Tone; icon: Component };

const dotClasses: Record<Tone, string> = {
    done: 'bg-emerald-600 text-white',
    pending: 'bg-amber-400 text-white animate-pulse',
    failed: 'bg-red-500 text-white',
    muted: 'bg-zinc-300 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
};

const timeline = computed<TimelineStep[]>(() => {
    const p = props.payment;
    const steps: TimelineStep[] = [
        {
            label: 'Payment link created',
            date: formatDateTime(p.created_at),
            tone: 'done',
            icon: Plus,
        },
    ];

    if (p.status === 'completed') {
        steps.push({
            label: 'Payment received',
            date: p.paid_at ? formatDateTime(p.paid_at) : '—',
            tone: 'done',
            icon: CheckCircle2,
        });
    } else if (p.status === 'failed') {
        steps.push({
            label: 'Payment failed',
            date: p.paid_at ? formatDateTime(p.paid_at) : 'Last attempt',
            tone: 'failed',
            icon: XCircle,
        });
    } else if (p.status === 'cancelled') {
        steps.push({
            label: 'Cancelled',
            date: '—',
            tone: 'muted',
            icon: Ban,
        });
    } else {
        steps.push({
            label: 'Awaiting payment',
            date: p.expires_at ? `Expires ${formatDateTime(p.expires_at)}` : 'No expiry',
            tone: 'pending',
            icon: Clock4,
        });
    }

    return steps;
});

const labelClass = 'text-[11px] font-semibold uppercase tracking-widest text-muted-foreground';
</script>

<template>
    <Head title="Payment Link" />

    <div class="space-y-6 p-4 sm:p-6">
        <!-- Top bar -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="space-y-2">
                <Button variant="ghost" size="sm" as-child class="-ml-2 text-muted-foreground">
                    <Link href="/payments">
                        <ArrowLeft class="mr-1 size-4" />
                        Back to payments
                    </Link>
                </Button>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                        Payment <span class="font-mono">{{ referenceLabel }}</span>
                    </h1>
                    <PaymentStatusBadge :status="payment.status" />
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Button v-if="payment.status === 'pending'" variant="outline" as-child>
                    <Link :href="`/payments/${payment.uuid}/edit`">
                        <Pencil class="mr-1 size-4" />
                        Edit
                    </Link>
                </Button>
                <Button
                    v-if="isAdmin"
                    variant="outline"
                    class="text-destructive hover:text-destructive"
                    :disabled="deleteForm.processing"
                    @click="deleteOpen = true"
                >
                    <Trash2 class="mr-1 size-4" />
                    Delete
                </Button>
            </div>
        </div>

        <div
            v-if="isAdmin && payment.card_locked_until"
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/20 dark:text-red-300"
            role="alert"
        >
            <div class="flex items-start gap-2">
                <LockKeyhole class="mt-0.5 size-4 shrink-0" />
                <span>
                    Card payments are paused until {{ formatDateTime(payment.card_locked_until) }} after too many failed attempts.
                    Please contact the customer to help them pay.
                </span>
            </div>
            <Button variant="outline" size="sm" :disabled="releasing" @click="releaseLock">
                <LockOpen class="mr-1 size-3.5" />
                Release lock
            </Button>
        </div>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            <!-- Main column -->
            <div class="min-w-0 space-y-6">
                <!-- Amount, progress, details -->
                <Card class="gap-0 overflow-hidden py-0">
                    <div class="h-1 bg-primary" aria-hidden="true" />

                    <div class="flex flex-wrap items-end justify-between gap-6 border-b border-dashed border-border px-6 py-7 sm:px-9">
                        <div>
                            <p :class="labelClass">Amount</p>
                            <div class="mt-1.5 flex items-baseline gap-2.5">
                                <span class="text-4xl font-semibold tracking-tight tabular-nums sm:text-6xl">
                                    {{ formatAmount(payment.amount, payment.currency) }}
                                </span>
                                <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ payment.currency.toUpperCase() }}
                                </span>
                            </div>
                        </div>
                        <div class="sm:text-right">
                            <p :class="labelClass">Brand</p>
                            <p class="mt-1 text-lg font-semibold">{{ payment.brand_name }}</p>
                        </div>
                    </div>

                    <!-- Progress -->
                    <ol class="flex gap-0 border-b border-border bg-muted/30 px-6 py-5 sm:px-9">
                        <li
                            v-for="(step, i) in timeline"
                            :key="i"
                            :class="['flex flex-col gap-2', i < timeline.length - 1 ? 'flex-1' : 'flex-none']"
                        >
                            <div class="flex items-center">
                                <span :class="['flex size-7 shrink-0 items-center justify-center rounded-full', dotClasses[step.tone]]">
                                    <component :is="step.icon" class="size-3.5" />
                                </span>
                                <span v-if="i < timeline.length - 1" class="mx-2 h-0.5 flex-1 rounded bg-border" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-sm font-semibold leading-tight">{{ step.label }}</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ step.date }}</p>
                            </div>
                        </li>
                    </ol>

                    <!-- Details -->
                    <dl class="grid grid-cols-1 gap-x-8 gap-y-6 px-6 py-7 sm:grid-cols-2 sm:px-9 xl:grid-cols-3">
                        <div>
                            <dt :class="labelClass">Client</dt>
                            <dd class="mt-1 font-semibold">{{ payment.client_name }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt :class="labelClass">Email</dt>
                            <dd class="mt-1 break-words">{{ payment.client_email }}</dd>
                        </div>
                        <div>
                            <dt :class="labelClass">Relationship manager</dt>
                            <dd class="mt-1">{{ payment.relationship_manager_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt :class="labelClass">Service</dt>
                            <dd class="mt-1">{{ payment.service ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt :class="labelClass">Package</dt>
                            <dd class="mt-1">{{ titleCase(payment.package) }}</dd>
                        </div>
                        <div>
                            <dt :class="labelClass">Reference</dt>
                            <dd class="mt-1 font-mono">{{ referenceLabel }}</dd>
                        </div>
                        <div v-if="payment.note" class="rounded-lg bg-muted/50 px-4 py-3 sm:col-span-2 xl:col-span-3">
                            <dt :class="labelClass">Note</dt>
                            <dd class="mt-1 text-foreground/80">{{ payment.note }}</dd>
                        </div>
                    </dl>
                </Card>

                <!-- Attempt history (admins only; the server sends null for everyone else) -->
                <Card v-if="attempts" class="gap-0 overflow-hidden py-0">
                    <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4 sm:px-6">
                        <h2 class="text-lg font-semibold">Payment attempts</h2>
                        <span class="rounded-md bg-muted px-2 py-1 text-xs font-semibold text-muted-foreground">Admins only</span>
                    </div>

                    <div v-if="attempts.rows.length || attempts.provider === 'mpgs'" class="overflow-x-auto">
                        <table
                            v-if="attempts.rows.length"
                            class="w-full text-sm max-sm:block [&_tbody]:max-sm:block [&_tr]:max-sm:block"
                        >
                            <thead class="max-sm:hidden">
                                <tr class="bg-muted/30 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">Started</th>
                                    <th class="px-4 py-3">{{ attempts.provider === 'mpgs' ? 'Order ID' : 'Transaction ID' }}</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in attempts.rows"
                                    :key="row.sequence"
                                    class="border-t border-border max-sm:m-3 max-sm:flex max-sm:flex-col max-sm:gap-1 max-sm:rounded-lg max-sm:border max-sm:p-3"
                                >
                                    <td class="px-4 py-3.5 align-top tabular-nums max-sm:p-0">{{ row.sequence }}</td>
                                    <td class="whitespace-nowrap px-4 py-3.5 align-top tabular-nums max-sm:p-0">{{ formatDateTime(row.started_at) }}</td>
                                    <td class="px-4 py-3.5 align-top font-mono text-xs max-sm:p-0">{{ row.reference ?? '—' }}</td>
                                    <td class="px-4 py-3.5 align-top max-sm:p-0">
                                        <PaymentStatusBadge
                                            :status="attemptTone[row.status] ?? 'cancelled'"
                                            :label="row.status.replace('_', ' ')"
                                        />
                                    </td>
                                    <td class="px-4 py-3.5 align-top sm:max-w-[34ch] max-sm:p-0">{{ row.reason ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="px-5 py-5 text-sm text-muted-foreground sm:px-6">No attempts yet. The client has not opened the payment link.</p>
                    </div>

                    <div v-else-if="attempts.provider === 'authorizenet'" class="space-y-3 px-5 py-5 sm:px-6">
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-4 text-sm sm:grid-cols-4">
                            <div>
                                <dt :class="labelClass">First attempt</dt>
                                <dd class="mt-1 tabular-nums">{{ attempts.summary.first_attempt_at ? formatDateTime(attempts.summary.first_attempt_at) : '—' }}</dd>
                            </div>
                            <div>
                                <dt :class="labelClass">Latest attempt</dt>
                                <dd class="mt-1 tabular-nums">{{ attempts.summary.last_attempt_at ? formatDateTime(attempts.summary.last_attempt_at) : '—' }}</dd>
                            </div>
                            <div class="min-w-0">
                                <dt :class="labelClass">Transaction ID</dt>
                                <dd class="mt-1 truncate font-mono text-xs">{{ attempts.summary.transaction_id ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt :class="labelClass">Status</dt>
                                <dd class="mt-1"><PaymentStatusBadge :status="attempts.summary.status" /></dd>
                            </div>
                        </dl>
                        <p class="text-xs text-muted-foreground">
                            No attempts were recorded for this payment. Only the first and latest attempt times were kept before attempts were stored.
                        </p>
                    </div>
                </Card>
            </div>

            <!-- Side rail -->
            <aside class="min-w-0 space-y-6">
                <Card class="gap-0 px-5 py-5">
                    <div :class="[labelClass, 'flex items-center gap-1.5']">
                        <Link2 class="size-3.5" />
                        Payment link
                    </div>
                    <div class="mt-3 break-all rounded-lg bg-muted/50 px-3 py-2.5 font-mono text-xs select-all">{{ shareableLink }}</div>
                    <Button class="mt-3 w-full" @click="copyLink">
                        <Check v-if="copied" class="size-4" />
                        <Copy v-else class="size-4" />
                        {{ copied ? 'Copied!' : 'Copy link' }}
                    </Button>
                    <p class="mt-3 text-xs text-muted-foreground">Share with your client to collect payment.</p>
                </Card>

                <Card v-if="canViewStripeAccount || payment.provider_reference" class="gap-0 px-5 py-5">
                    <h2 class="text-lg font-semibold">Processed through</h2>
                    <div v-if="canViewStripeAccount" class="mt-4 flex items-center gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <PaymentProviderBadge :provider="payment.provider" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-semibold">{{ providerName }}</p>
                            <p class="truncate text-sm text-muted-foreground">{{ payment.account_name ?? '—' }}</p>
                        </div>
                    </div>
                    <div
                        v-if="payment.provider_reference"
                        :class="canViewStripeAccount ? 'mt-4 border-t border-border pt-4' : 'mt-4'"
                    >
                        <p :class="labelClass">{{ providerReferenceLabel }}</p>
                        <p class="mt-1 break-all font-mono text-xs">{{ payment.provider_reference }}</p>
                    </div>
                </Card>
            </aside>
        </div>
    </div>

    <ConfirmDeleteDialog
        v-model:open="deleteOpen"
        :title="`Delete payment ${payment.reference_code ?? ''}?`"
        description="This will remove the payment from the list. The payment link will no longer be accessible."
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
