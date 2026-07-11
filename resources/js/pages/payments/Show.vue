<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, type Component } from 'vue';
import {
    ArrowLeft, Ban, Briefcase, Building2, Check, CheckCircle2,
    Clock4, Copy, CreditCard, FileText, Hash, Handshake, Link2,
    Mail, Package, Pencil, Plus, Trash2, User, XCircle,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import PaymentProviderBadge from '@/components/PaymentProviderBadge.vue';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import {
    Card, CardContent, CardHeader, CardTitle,
} from '@/components/ui/card';

type PaymentDetail = {
    uuid: string;
    reference_code: string | null;
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
};

const props = defineProps<{ payment: PaymentDetail; isAdmin: boolean; canViewStripeAccount: boolean }>();

const deleteOpen = ref(false);
const deleteForm = useForm({});

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
const shareableLink = `${window.location.origin}/pay/${props.payment.uuid}`;

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
        currency === 'gbp' ? 'en-GB' : 'en-US',
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

type Tone = 'done' | 'pending' | 'failed' | 'muted';
type TimelineStep = { label: string; date: string; tone: Tone; icon: Component };

const dotClasses: Record<Tone, string> = {
    done: 'bg-emerald-500 text-white ring-emerald-100 dark:ring-emerald-900/40',
    pending: 'bg-amber-400 text-white ring-amber-100 dark:ring-amber-900/40 animate-pulse',
    failed: 'bg-red-500 text-white ring-red-100 dark:ring-red-900/40',
    muted: 'bg-zinc-300 text-zinc-600 ring-zinc-100 dark:bg-zinc-700 dark:text-zinc-300 dark:ring-zinc-800',
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
</script>

<template>
    <Head title="Payment Link" />

    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/payments">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to payments
                </Link>
            </Button>
            <div class="flex items-center gap-2">
                <Button v-if="payment.status === 'pending'" variant="outline" size="sm" as-child>
                    <Link :href="`/payments/${payment.uuid}/edit`">
                        <Pencil class="size-4 mr-1" />
                        Edit
                    </Link>
                </Button>
                <Button
                    v-if="isAdmin"
                    variant="outline"
                    size="sm"
                    class="text-destructive hover:text-destructive"
                    :disabled="deleteForm.processing"
                    @click="deleteOpen = true"
                >
                    <Trash2 class="size-4 mr-1" />
                    Delete
                </Button>
            </div>
        </div>

        <!-- Hero: amount anchor + shareable link -->
        <Card class="overflow-hidden">
            <div class="grid gap-px bg-border/60 md:grid-cols-[1.15fr_1fr]">
                <!-- Amount -->
                <div class="flex flex-col gap-6 bg-card p-6 sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Brand</p>
                            <p class="mt-0.5 truncate text-sm font-semibold">{{ payment.brand_name }}</p>
                        </div>
                        <PaymentStatusBadge :status="payment.status" />
                    </div>

                    <!-- Amount -->
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Amount</p>
                        <div class="mt-1 flex items-baseline gap-2">
                            <span class="text-4xl font-semibold tracking-tight tabular-nums sm:text-5xl">
                                {{ formatAmount(payment.amount, payment.currency) }}
                            </span>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                {{ payment.currency.toUpperCase() }}
                            </span>
                        </div>
                    </div>

                    <!-- Meta -->
                    <div class="flex items-end justify-between gap-4 border-t border-border pt-4">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Billed to</p>
                            <p class="mt-0.5 truncate text-sm font-medium">{{ payment.client_name }}</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Reference</p>
                            <p class="mt-0.5 font-mono text-sm font-medium">{{ referenceLabel }}</p>
                        </div>
                    </div>
                </div>

                <!-- Shareable link -->
                <div class="flex flex-col justify-center gap-3 bg-[#F7F5F2] p-6 sm:p-8 dark:bg-muted/30 min-w-0">
                    <div class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                        <Link2 class="size-3.5" />
                        Payment link
                    </div>
                    <div class="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2.5">
                        <span class="flex-1 truncate font-mono text-sm select-all">{{ shareableLink }}</span>
                        <Button variant="outline" size="sm" @click="copyLink">
                            <Check v-if="copied" class="size-4 text-emerald-600" />
                            <Copy v-else class="size-4" />
                            {{ copied ? 'Copied!' : 'Copy' }}
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">Share with your client to collect payment.</p>
                </div>
            </div>
        </Card>

        <!-- Details + timeline -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 items-start">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base">Payment details</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <User class="size-3.5 shrink-0" />Client
                            </dt>
                            <dd class="font-medium">{{ payment.client_name }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Mail class="size-3.5 shrink-0" />Email
                            </dt>
                            <dd>{{ payment.client_email }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Building2 class="size-3.5 shrink-0" />Brand
                            </dt>
                            <dd>{{ payment.brand_name }}</dd>
                        </div>
                        <div v-if="canViewStripeAccount">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <CreditCard class="size-3.5 shrink-0" />Provider
                            </dt>
                            <dd class="flex items-center gap-1.5">
                                <PaymentProviderBadge :provider="payment.provider" />
                                {{ payment.provider_label }}
                            </dd>
                        </div>
                        <div v-if="canViewStripeAccount">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <CreditCard class="size-3.5 shrink-0" />{{ payment.provider_label }} Account
                            </dt>
                            <dd>{{ payment.account_name }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Handshake class="size-3.5 shrink-0" />Relationship Manager
                            </dt>
                            <dd>{{ payment.relationship_manager_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Briefcase class="size-3.5 shrink-0" />Service
                            </dt>
                            <dd>{{ payment.service ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Package class="size-3.5 shrink-0" />Package
                            </dt>
                            <dd>{{ titleCase(payment.package) }}</dd>
                        </div>
                        <div v-if="payment.note" class="col-span-2">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <FileText class="size-3.5 shrink-0" />Note
                            </dt>
                            <dd class="mt-1 text-muted-foreground italic">{{ payment.note }}</dd>
                        </div>
                        <div v-if="payment.provider_reference" class="col-span-2">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Hash class="size-3.5 shrink-0" />{{ payment.provider === 'revolut' ? 'Revolut Order' : payment.provider === 'square' ? 'Square Payment ID' : payment.provider === 'viva' ? 'Viva Order/Transaction ID' : 'Stripe PI' }}
                            </dt>
                            <dd class="font-mono text-xs truncate">{{ payment.provider_reference }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Timeline</CardTitle>
                </CardHeader>
                <CardContent>
                    <ol class="relative">
                        <li
                            v-for="(step, i) in timeline"
                            :key="i"
                            class="relative flex gap-3 pb-6 last:pb-0"
                        >
                            <!-- connector -->
                            <span
                                v-if="i < timeline.length - 1"
                                class="absolute left-[11px] top-7 h-[calc(100%-1rem)] w-px bg-border"
                                aria-hidden="true"
                            />
                            <span
                                :class="[
                                    'flex size-[22px] shrink-0 items-center justify-center rounded-full ring-4',
                                    dotClasses[step.tone],
                                ]"
                            >
                                <component :is="step.icon" class="size-3" />
                            </span>
                            <div class="-mt-px min-w-0 flex-1">
                                <p class="text-sm font-medium leading-tight">{{ step.label }}</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ step.date }}</p>
                            </div>
                        </li>
                    </ol>
                </CardContent>
            </Card>
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
