<script setup lang="ts">
import { Check, Copy, Landmark } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

type BankDetailAccount = {
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
};

type LedgerRow = {
    label: string;
    value: string;
    mono?: boolean;
    breakAll?: boolean;
};

const props = defineProps<{ account: BankDetailAccount }>();

const rows = computed((): LedgerRow[] => {
    const account = props.account;
    const list: LedgerRow[] = [
        { label: 'Account Name', value: account.account_name },
        { label: 'Account No.', value: account.account_number, mono: true },
    ];

    if (account.sort_code) {
        list.push({ label: 'Sort Code', value: account.sort_code, mono: true });
    }

    if (account.routing_number) {
        list.push({ label: 'Routing No.', value: account.routing_number, mono: true });
    }

    if (account.iban) {
        list.push({ label: 'IBAN', value: account.iban, mono: true, breakAll: true });
    }

    if (account.swift_bic) {
        list.push({ label: 'SWIFT/BIC', value: account.swift_bic, mono: true });
    }

    if (account.bank_address) {
        list.push({ label: 'Bank Address', value: account.bank_address });
    }

    return list;
});

function formatDetails(): string {
    const lines = rows.value.map((row) => `${row.label}: ${row.value}`);
    lines.splice(2, 0, `Currency: ${props.account.currency.toUpperCase()}`);

    return [`Bank: ${props.account.bank_name}`, ...lines].join('\n');
}

const copied = ref(false);
let resetTimer: ReturnType<typeof setTimeout> | undefined;

async function copyDetails(): Promise<void> {
    const text = formatDetails();

    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }

    copied.value = false;
    requestAnimationFrame(() => {
        copied.value = true;
    });
    clearTimeout(resetTimer);
    resetTimer = setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <Card class="overflow-hidden transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg">
        <div class="px-5 pt-5">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">
                        <Landmark class="size-4 text-muted-foreground" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold leading-snug">{{ account.bank_name }}</h3>
                        <p v-if="account.bank_country" class="text-xs text-muted-foreground">
                            {{ account.bank_country }}
                        </p>
                    </div>
                </div>
                <Badge variant="outline" class="shrink-0 uppercase">{{ account.currency }}</Badge>
            </div>
        </div>

        <div class="px-5 pt-4 pb-5">
            <dl class="divide-y divide-border text-sm">
                <div v-for="row in rows" :key="row.label" class="flex items-start justify-between gap-4 py-2 first:pt-0 last:pb-0">
                    <dt class="shrink-0 pt-px text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                        {{ row.label }}
                    </dt>
                    <dd
                        class="text-right font-medium text-foreground"
                        :class="[row.mono && 'font-mono tabular-nums tracking-tight', row.breakAll && 'break-all']"
                    >
                        {{ row.value }}
                    </dd>
                </div>
            </dl>

            <Button variant="outline" size="sm" class="mt-5 w-full" @click="copyDetails">
                <Check v-if="copied" class="size-4 text-emerald-600" />
                <Copy v-else class="size-4" />
                {{ copied ? 'Copied to clipboard' : 'Copy Details' }}
            </Button>
        </div>
    </Card>
</template>
