<script setup lang="ts">
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ZelleIcon from '@/components/icons/ZelleIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

type ZelleDetailAccount = {
    id: number;
    account_name: string;
    email: string;
    mobile_number: string | null;
    currency: string;
};

type LedgerRow = {
    label: string;
    value: string;
    mono?: boolean;
    breakAll?: boolean;
};

const props = defineProps<{ account: ZelleDetailAccount }>();

const rows = computed((): LedgerRow[] => {
    const account = props.account;
    const list: LedgerRow[] = [
        { label: 'Account Name', value: account.account_name },
        { label: 'Email', value: account.email, breakAll: true },
    ];

    if (account.mobile_number) {
        list.push({ label: 'Mobile', value: account.mobile_number, mono: true });
    }

    list.push({ label: 'Currency', value: account.currency.toUpperCase() });

    return list;
});

async function writeToClipboard(text: string): Promise<void> {
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
}

// One "Copied" flag at a time: a row label, or 'all' for the full block.
const copiedKey = ref<string | null>(null);
let resetTimer: ReturnType<typeof setTimeout> | undefined;

async function copy(key: string, text: string): Promise<void> {
    await writeToClipboard(text);

    copiedKey.value = key;
    clearTimeout(resetTimer);
    resetTimer = setTimeout(() => {
        copiedKey.value = null;
    }, 2000);
}

function copyAll(): Promise<void> {
    return copy('all', rows.value.map((row) => `${row.label}: ${row.value}`).join('\n'));
}
</script>

<template>
    <Card class="overflow-hidden transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg">
        <div class="px-5 pt-5">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">
                        <ZelleIcon class="size-4 text-muted-foreground" />
                    </div>
                    <h3 class="truncate font-semibold leading-snug">{{ account.account_name }}</h3>
                </div>
                <Badge variant="outline" class="shrink-0 uppercase">{{ account.currency }}</Badge>
            </div>
        </div>

        <div class="px-5 pt-4 pb-5">
            <dl class="divide-y divide-border text-sm">
                <div v-for="row in rows" :key="row.label" class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <dt class="shrink-0 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                        {{ row.label }}
                    </dt>
                    <dd class="flex min-w-0 items-center gap-1.5">
                        <span
                            class="text-right font-medium text-foreground"
                            :class="[row.mono && 'font-mono tabular-nums tracking-tight', row.breakAll && 'break-all']"
                        >
                            {{ row.value }}
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-7 shrink-0"
                            :aria-label="`Copy ${row.label}`"
                            @click="copy(row.label, row.value)"
                        >
                            <Check v-if="copiedKey === row.label" class="size-3.5 text-emerald-600" />
                            <Copy v-else class="size-3.5" />
                        </Button>
                    </dd>
                </div>
            </dl>

            <Button variant="outline" size="sm" class="mt-5 w-full" @click="copyAll">
                <Check v-if="copiedKey === 'all'" class="size-4 text-emerald-600" />
                <Copy v-else class="size-4" />
                {{ copiedKey === 'all' ? 'Copied' : 'Copy all' }}
            </Button>
        </div>
    </Card>
</template>
