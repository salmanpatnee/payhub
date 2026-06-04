<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    ArrowLeft, Banknote, Briefcase, Building2, Calendar,
    CalendarCheck, CalendarX, Check, Copy, CreditCard,
    FileText, Hash, Handshake, Mail, Package, Pencil, Trash2, User,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import {
    Card, CardContent, CardDescription,
    CardHeader, CardTitle,
} from '@/components/ui/card';

type PaymentDetail = {
    uuid: string;
    reference_code: number | null;
    amount: number;
    currency: string;
    status: string;
    client_name: string;
    client_email: string;
    service: string | null;
    package: string | null;
    note: string | null;
    brand_name: string;
    account_name: string;
    relationship_manager_name: string | null;
    created_at: string;
    stripe_payment_intent_id: string | null;
    paid_at: string | null;
    expires_at: string | null;
};

const props = defineProps<{ payment: PaymentDetail; isAdmin: boolean }>();

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


const relativeTime = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

function formatDate(iso: string): string {
    const d = new Date(iso);
    const diffSec = Math.round((Date.now() - d.getTime()) / 1000);

    if (diffSec < 60) return 'just now';

    const diffMin = Math.round(diffSec / 60);
    if (diffMin < 60) return relativeTime.format(-diffMin, 'minute');

    const diffHour = Math.round(diffMin / 60);
    if (diffHour < 24) return relativeTime.format(-diffHour, 'hour');

    const month = d.toLocaleDateString('en-GB', { month: 'short' });
    return `${d.getDate()}-${month}-${d.getFullYear()}`;
}

function titleCase(str: string | null): string {
    if (!str) return '—';
    return str.charAt(0).toUpperCase() + str.slice(1);
}
</script>

<template>
    <Head title="Payment Link" />

    <div class="p-6 max-w-5xl space-y-4">
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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-stretch">
            <!-- Left: Payment Summary -->
            <Card class="h-full">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardTitle>Payment summary</CardTitle>
                        <PaymentStatusBadge :status="payment.status" />
                    </div>
                </CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div v-if="payment.reference_code" class="col-span-2">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Hash class="size-3.5 shrink-0" />Reference
                            </dt>
                            <dd class="font-mono font-semibold">{{ String(payment.reference_code).padStart(6, '0') }}</dd>
                        </div>
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
                                <Banknote class="size-3.5 shrink-0" />Amount
                            </dt>
                            <dd class="font-mono font-semibold">{{ formatAmount(payment.amount, payment.currency) }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Building2 class="size-3.5 shrink-0" />Brand
                            </dt>
                            <dd>{{ payment.brand_name }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <CreditCard class="size-3.5 shrink-0" />Stripe Account
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
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Calendar class="size-3.5 shrink-0" />Created
                            </dt>
                            <dd>{{ formatDate(payment.created_at) }}</dd>
                        </div>
                        <div v-if="payment.paid_at">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <CalendarCheck class="size-3.5 shrink-0" />Paid at
                            </dt>
                            <dd>{{ new Date(payment.paid_at).toLocaleString() }}</dd>
                        </div>
                        <div v-if="payment.expires_at">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <CalendarX class="size-3.5 shrink-0" />Expires
                            </dt>
                            <dd>{{ new Date(payment.expires_at).toLocaleString() }}</dd>
                        </div>
                        <div v-if="payment.stripe_payment_intent_id" class="col-span-2">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground/60">
                                <Hash class="size-3.5 shrink-0" />Stripe PI
                            </dt>
                            <dd class="font-mono text-xs truncate">{{ payment.stripe_payment_intent_id }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <!-- Right: Shareable Link + Fee Breakdown -->
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Payment link created</CardTitle>
                        <CardDescription>Share this link with your client to collect payment.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3">
                            <span class="flex-1 text-sm font-mono truncate select-all">{{ shareableLink }}</span>
                            <Button variant="outline" size="sm" @click="copyLink">
                                <Check v-if="copied" class="size-4 text-green-600" />
                                <Copy v-else class="size-4" />
                                {{ copied ? 'Copied!' : 'Copy' }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>

    <ConfirmDeleteDialog
        v-model:open="deleteOpen"
        :title="`Delete payment ${payment.reference_code != null ? '#' + String(payment.reference_code).padStart(6, '0') : ''}?`"
        description="This will remove the payment from the list. The payment link will no longer be accessible."
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
