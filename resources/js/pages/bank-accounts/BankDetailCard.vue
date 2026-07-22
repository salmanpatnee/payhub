<script setup lang="ts">
import { Check, Copy, Landmark } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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

const theme = computed(() => {
    if (props.account.currency === 'usd') {
        return {
            header: 'bg-[#f7ecd8] dark:bg-[#2b2210]',
            headerText: 'text-[#5c4013] dark:text-[#e8cf97]',
            label: 'text-[#8a6a2f] dark:text-[#c2a765]',
            seal: 'bg-[#a9762c] dark:bg-[#c2942f] text-[#fdf6e8]',
            perforation: 'border-[#d8c090] dark:border-[#4a3a1a]',
            watermark: 'text-[#a9762c]',
            row: 'divide-[#e6d4ac] dark:divide-[#3d3018]',
            ring: 'ring-[#a9762c]/15 dark:ring-[#e8cf97]/10',
        };
    }

    return {
        header: 'bg-[#e3ede9] dark:bg-[#132420]',
        headerText: 'text-[#12362c] dark:text-[#bfe3d6]',
        label: 'text-[#3f6f60] dark:text-[#8bb8a8]',
        seal: 'bg-[#1f5c48] dark:bg-[#2f7a61] text-[#eafaf3]',
        perforation: 'border-[#b9d5cb] dark:border-[#264a3d]',
        watermark: 'text-[#1f5c48]',
        row: 'divide-[#cfe4dc] dark:divide-[#20392f]',
        ring: 'ring-[#1f5c48]/15 dark:ring-[#bfe3d6]/10',
    };
});

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
    <div
        class="group relative overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm ring-1 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg"
        :class="theme.ring"
    >
        <!-- Header band: bank identity + wax-seal currency badge -->
        <div class="relative px-5 pt-5 pb-7" :class="theme.header">
            <Landmark
                class="pointer-events-none absolute -top-4 -right-4 size-28 rotate-12 opacity-[0.08]"
                :class="theme.watermark"
            />
            <p class="relative text-[10px] font-semibold uppercase tracking-[0.25em]" :class="theme.label">
                Company Account
            </p>
            <h3 class="relative mt-1 pr-14 font-semibold text-lg leading-snug tracking-tight" :class="theme.headerText">
                {{ account.bank_name }}
            </h3>
            <p v-if="account.bank_country" class="relative mt-0.5 text-xs" :class="theme.label">
                {{ account.bank_country }}
            </p>
        </div>

        <!-- Perforated seam, ticket-stub style — cut circles clipped by the card's overflow-hidden -->
        <div class="relative border-t-2 border-dashed" :class="theme.perforation">
            <span class="absolute -top-2.5 -left-3 size-5 rounded-full bg-background" />
            <span class="absolute -top-2.5 -right-3 size-5 rounded-full bg-background" />
        </div>

        <!-- Currency seal, stamped across the perforation -->
        <div
            class="absolute top-[4.75rem] right-5 flex size-14 -translate-y-1/2 rotate-[-8deg] items-center justify-center rounded-full border-2 border-dashed border-white/50 text-[11px] font-bold uppercase tracking-wider shadow-md transition-transform duration-300 group-hover:rotate-0 dark:border-black/20"
            :class="theme.seal"
        >
            {{ account.currency }}
        </div>

        <!-- Ledger body -->
        <div class="px-5 pt-4 pb-5">
            <dl class="divide-y divide-dashed text-sm" :class="theme.row">
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

            <button
                type="button"
                class="relative mt-5 flex w-full cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-lg border-2 border-dashed py-2.5 text-sm font-semibold uppercase tracking-wide transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                :class="copied
                    ? [theme.seal, 'border-transparent animate-stamp']
                    : ['border-border text-muted-foreground hover:border-foreground/40 hover:text-foreground']"
                @click="copyDetails"
            >
                <Check v-if="copied" class="size-4" />
                <Copy v-else class="size-4" />
                {{ copied ? 'Copied to clipboard' : 'Copy Details' }}
            </button>
        </div>
    </div>
</template>

<style scoped>
@keyframes stamp-hit {
    0% {
        transform: scale(1) rotate(0deg);
    }
    35% {
        transform: scale(1.04) rotate(-1deg);
    }
    60% {
        transform: scale(0.98) rotate(0.5deg);
    }
    100% {
        transform: scale(1) rotate(0deg);
    }
}

.animate-stamp {
    animation: stamp-hit 0.35s ease-out;
}
</style>
