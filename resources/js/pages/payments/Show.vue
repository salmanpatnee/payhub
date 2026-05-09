<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeft, Check, Copy } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card, CardContent, CardDescription,
    CardHeader, CardTitle,
} from '@/components/ui/card';

type PaymentDetail = {
    uuid: string;
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
    created_at: string;
    stripe_payment_intent_id: string | null;
    paid_at: string | null;
    expires_at: string | null;
};

const props = defineProps<{ payment: PaymentDetail }>();

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

function statusClass(status: string): string {
    if (status === 'completed')  return 'border-green-500 bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400';
    if (status === 'pending')    return 'border-amber-400 bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400';
    if (status === 'failed')     return 'border-red-500 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400';
    if (status === 'cancelled')  return 'border-gray-400 bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-400';
    return '';
}

function titleCase(str: string | null): string {
    if (!str) return '—';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

const feeBreakdown = computed(() => {
    const amt = props.payment.amount / 100;
    const currency = props.payment.currency;
    const fee = currency === 'gbp' ? amt * 0.015 + 0.20 : amt * 0.029 + 0.30;
    const receive = amt - fee;
    const locale = currency === 'gbp' ? 'en-GB' : 'en-US';
    const curr = currency.toUpperCase();
    const fmt = (n: number) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: curr }).format(n);
    return { charge: fmt(amt), fee: fmt(fee), receive: fmt(receive) };
});
</script>

<template>
    <Head title="Payment Link" />

    <div class="p-6 max-w-5xl space-y-4">
        <Button variant="ghost" size="sm" as-child class="-ml-2">
            <Link href="/payments">
                <ArrowLeft class="size-4 mr-1" />
                Back to payments
            </Link>
        </Button>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-stretch">
            <!-- Left: Payment Summary -->
            <Card class="h-full">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardTitle>Payment summary</CardTitle>
                        <Badge variant="outline" :class="statusClass(payment.status)">{{ payment.status }}</Badge>
                    </div>
                </CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Client</dt>
                            <dd class="font-medium">{{ payment.client_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Email</dt>
                            <dd>{{ payment.client_email }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Amount</dt>
                            <dd class="font-mono font-semibold">{{ formatAmount(payment.amount, payment.currency) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Brand</dt>
                            <dd>{{ payment.brand_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Stripe Account</dt>
                            <dd>{{ payment.account_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Service</dt>
                            <dd>{{ payment.service ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Package</dt>
                            <dd>{{ titleCase(payment.package) }}</dd>
                        </div>
                        <div v-if="payment.note" class="col-span-2">
                            <dt class="text-muted-foreground">Note</dt>
                            <dd class="mt-1 text-muted-foreground italic">{{ payment.note }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Created</dt>
                            <dd>{{ new Date(payment.created_at).toLocaleDateString() }}</dd>
                        </div>
                        <div v-if="payment.paid_at">
                            <dt class="text-muted-foreground">Paid at</dt>
                            <dd>{{ new Date(payment.paid_at).toLocaleString() }}</dd>
                        </div>
                        <div v-if="payment.expires_at">
                            <dt class="text-muted-foreground">Expires</dt>
                            <dd>{{ new Date(payment.expires_at).toLocaleString() }}</dd>
                        </div>
                        <div v-if="payment.stripe_payment_intent_id" class="col-span-2">
                            <dt class="text-muted-foreground">Stripe PI</dt>
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

                <Card>
                    <CardHeader>
                        <CardTitle>Fee breakdown</CardTitle>
                        <CardDescription>Based on standard Stripe rates.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
                            <div class="flex justify-between py-1">
                                <span class="text-muted-foreground">Charge amount</span>
                                <span class="font-medium">{{ feeBreakdown.charge }}</span>
                            </div>
                            <div class="flex justify-between py-1">
                                <span class="text-muted-foreground">Stripe fee</span>
                                <span class="text-destructive">− {{ feeBreakdown.fee }}</span>
                            </div>
                            <div class="flex justify-between py-1 border-t border-border mt-1 pt-2">
                                <span class="font-semibold">You receive</span>
                                <span class="font-semibold text-green-600">{{ feeBreakdown.receive }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
