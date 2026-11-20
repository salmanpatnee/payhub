<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, Coins, Lock, Plus } from 'lucide-vue-next';
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card, CardContent, CardDescription, CardHeader, CardTitle,
} from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select, SelectContent, SelectItem,
    SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { withBase } from '@/lib/basePath';

type BrandOption = { id: number; name: string };
type AccountOption = { id: number; account_name?: string; provider: string; currency?: string; supports_pkr?: boolean };
type RmOption = { id: number; name: string };
type AgentCurrencyOption = { currency: string; enabled: boolean; reason: string | null };

const props = defineProps<{
    brands: BrandOption[];
    accounts: AccountOption[];
    agentCurrencies: AgentCurrencyOption[];
    isAccountLocked: boolean;
    relationshipManagers: RmOption[];
    countries: { id: string; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'New payment', href: '/payments/create' },
        ],
    },
});

const form = useForm({
    brand_id:                  '',
    provider:                  '',
    account_id:                '',
    relationship_manager_id:   '',
    currency:                  '',
    amount:                    '',
    client_name:               '',
    client_email:              '',
    country:                   '',
    service:                   '',
    package:                   '',
    note:                      '',
});

const providerLabel: Record<string, string> = { stripe: 'Stripe', revolut: 'Revolut', square: 'Square', viva: 'Viva', clover: 'Clover', mpgs: 'MPGS', authorizenet: 'Authorize.net', whop: 'Whop', kurv: 'Kurv', nmi: 'NMI', worldpay: 'Worldpay' };
const currencySymbol: Record<string, string> = { usd: '$', gbp: '£', pkr: 'Rs' };

// The account selector encodes "provider:id" since ids collide across providers.
const accountValue = ref('');
const selectedAccount = computed(() => props.accounts.find(a => `${a.provider}:${a.id}` === accountValue.value));

// Agents are locked to their assigned account, so they never choose one.
const accountChosen = computed(() => props.isAccountLocked || selectedAccount.value !== undefined);
const isCurrencyLocked = computed(() => selectedAccount.value?.currency != null);

// PKR is offered only for an MPGS account the bank has enabled PKR on.
const canUsePkr = computed(() => selectedAccount.value?.provider === 'mpgs' && selectedAccount.value.supports_pkr === true);

type CurrencyChoice = { value: string; enabled: boolean; reason: string | null };

// The account decides which currencies are allowed.
const allowedCurrencies = computed<CurrencyChoice[]>(() => {
    if (props.isAccountLocked) {
        return props.agentCurrencies.map(c => ({ value: c.currency, enabled: c.enabled, reason: c.reason }));
    }

    const account = selectedAccount.value;

    if (!account) {
        return [];
    }

    if (account.currency) {
        return [{ value: account.currency, enabled: true, reason: null }];
    }

    const list: CurrencyChoice[] = [
        { value: 'usd', enabled: true, reason: null },
        { value: 'gbp', enabled: true, reason: null },
    ];

    if (canUsePkr.value) {
        list.push({ value: 'pkr', enabled: true, reason: null });
    }

    return list;
});

const showCurrencyToggle = computed(() => allowedCurrencies.value.length > 1);
// Empty until a currency is known, so no placeholder character can look like a minus sign.
const symbol = computed(() => currencySymbol[form.currency] ?? '');

watch(accountValue, (val) => {
    const [provider, id] = val.split(':');
    form.provider = provider ?? '';
    form.account_id = id ?? '';

    if (selectedAccount.value?.currency) {
        form.currency = selectedAccount.value.currency;
    }

    // Keep the amount; drop the currency only when the new account does not allow it.
    if (form.currency && !allowedCurrencies.value.some(c => c.value === form.currency && c.enabled)) {
        form.currency = '';
    }

    // When the account takes more than one currency, start on USD (or the first enabled one).
    if (!form.currency) {
        const enabled = allowedCurrencies.value.filter(c => c.enabled);

        if (enabled.length > 1) {
            form.currency = enabled.some(c => c.value === 'usd') ? 'usd' : enabled[0].value;
        }
    }
});

function onCurrency(value: unknown): void {
    // A single choice toggle reports an empty value when the active item is tapped again; ignore it.
    if (typeof value === 'string' && value !== '') {
        form.currency = value;
    }
}

// Digits and one dot, at most two decimals, so the phone keypad cannot produce an odd value.
function onAmountInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const cleaned = el.value.replace(/[^0-9.]/g, '');
    const [whole, ...rest] = cleaned.split('.');
    const next = rest.length ? `${whole}.${rest.join('').slice(0, 2)}` : whole;

    form.amount = next;
    el.value = next;
}

// Country, package and note are optional and folded away until needed.
const moreOpen = ref(false);
watch(() => [form.errors.package, form.errors.note], (errors) => {
    if (errors.some(Boolean)) {
        moreOpen.value = true;
    }
});

const brandName = computed(() => props.brands.find(b => String(b.id) === form.brand_id)?.name ?? null);

// Remember the last brand, account and manager on this device.
const DEFAULTS_KEY = 'payments.create.defaults';

function loadDefaults(): void {
    try {
        const stored = JSON.parse(localStorage.getItem(DEFAULTS_KEY) ?? '{}') as Record<string, string>;

        if (stored.brand_id && props.brands.some(b => String(b.id) === stored.brand_id)) {
            form.brand_id = stored.brand_id;
        }

        if (stored.relationship_manager_id && props.relationshipManagers.some(r => String(r.id) === stored.relationship_manager_id)) {
            form.relationship_manager_id = stored.relationship_manager_id;
        }

        if (!props.isAccountLocked && stored.account && props.accounts.some(a => `${a.provider}:${a.id}` === stored.account)) {
            accountValue.value = stored.account;
        }
    } catch {
        // Storage can be blocked; the form works without it.
    }
}

function saveDefaults(): void {
    try {
        localStorage.setItem(DEFAULTS_KEY, JSON.stringify({
            brand_id: form.brand_id,
            relationship_manager_id: form.relationship_manager_id,
            account: accountValue.value,
        }));
    } catch {
        // Ignore blocked storage.
    }
}

onMounted(() => {
    loadDefaults();

    if (props.isAccountLocked) {
        const enabledCurrencies = props.agentCurrencies.filter(c => c.enabled);

        if (enabledCurrencies.length === 1) {
            form.currency = enabledCurrencies[0].currency;
        } else if (enabledCurrencies.length > 1) {
            form.currency = enabledCurrencies.some(c => c.currency === 'usd') ? 'usd' : enabledCurrencies[0].currency;
        }
    }

    // Focus the first thing to fill in, but only on wider screens so a phone keyboard does not pop up by itself.
    if (window.matchMedia('(min-width: 768px)').matches) {
        document.getElementById(accountChosen.value ? 'amount' : 'account_id')?.focus();
    }
});

async function copyLink(uuid: string, referenceSlug: string | null): Promise<boolean> {
    const url = `${window.location.origin}${withBase(`/pay/${uuid}${referenceSlug ? `/${referenceSlug}` : ''}`)}`;

    try {
        await navigator.clipboard.writeText(url);

        return true;
    } catch {
        return false;
    }
}

function submit() {
    saveDefaults();

    form.post('/payments', {
        onSuccess: async (page) => {
            const created = (page.props as { payment?: { uuid: string; reference_slug: string | null } }).payment;

            if (created && await copyLink(created.uuid, created.reference_slug)) {
                toast.success('Payment link copied');
            }
        },
    });
}
</script>

<template>
    <Head title="New Payment" />

    <div class="space-y-4 p-4 md:p-6">
        <Button variant="ghost" size="sm" as-child class="-ml-2">
            <Link href="/payments">
                <ArrowLeft class="size-4 mr-1" />
                Back to payments
            </Link>
        </Button>

        <Card class="gap-0 py-0">
            <CardHeader class="px-4 pb-0 pt-5 md:px-6">
                <CardTitle>New payment</CardTitle>
                <CardDescription class="max-md:sr-only">Create a payment link to send to your client.</CardDescription>
            </CardHeader>
            <CardContent class="px-0">
                <form id="create-payment-form" @submit.prevent="submit">

                    <!-- Payment: the account comes first because it decides which currencies are allowed -->
                    <section class="px-4 pb-2 pt-4 md:px-6">
                        <h2 class="mb-3 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Payment</h2>
                        <div class="grid gap-x-6 gap-y-4 md:grid-cols-2">
                            <!-- Payment account — hidden for agents (locked to their assigned account). -->
                            <div v-if="!isAccountLocked" class="grid gap-2 md:col-span-2">
                                <Label for="account_id">Payment account <span class="text-destructive">*</span></Label>
                                <Select v-model="accountValue">
                                    <SelectTrigger id="account_id" class="w-full data-[size=default]:h-11 md:data-[size=default]:h-9">
                                        <SelectValue placeholder="Select a payment account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="account in props.accounts"
                                            :key="`${account.provider}:${account.id}`"
                                            :value="`${account.provider}:${account.id}`"
                                        >{{ account.account_name }} ({{ providerLabel[account.provider] ?? account.provider }})</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError class="mt-1" :message="form.errors.account_id" />
                            </div>

                            <!-- Amount -->
                            <div class="grid gap-2">
                                <Label for="amount">Amount <span class="text-destructive">*</span></Label>
                                <div
                                    class="flex h-14 overflow-hidden rounded-lg border bg-background transition-colors focus-within:ring-2 focus-within:ring-ring/50"
                                    :class="{ 'bg-muted/50 opacity-70': !accountChosen }"
                                >
                                    <span class="flex w-12 shrink-0 items-center justify-center border-r bg-muted text-lg font-semibold text-muted-foreground">
                                        <template v-if="symbol">{{ symbol }}</template>
                                        <Coins v-else class="size-5" aria-hidden="true" />
                                    </span>
                                    <input
                                        id="amount"
                                        :value="form.amount"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        placeholder="0.00"
                                        :disabled="!accountChosen"
                                        class="min-w-0 flex-1 bg-transparent px-3.5 text-2xl font-semibold tabular-nums outline-none placeholder:text-muted-foreground/50 disabled:cursor-not-allowed"
                                        @input="onAmountInput"
                                    >
                                </div>
                                <p v-if="!accountChosen" class="text-xs text-muted-foreground">Choose a payment account first.</p>
                                <InputError class="mt-1" :message="form.errors.amount" />
                            </div>

                            <!-- Currency — follows the account: locked, a toggle, or per-item disabled for agents. -->
                            <div class="grid content-start gap-2">
                                <Label for="currency" class="gap-1.5">
                                    Currency <span class="text-destructive">*</span>
                                    <span
                                        v-if="isCurrencyLocked"
                                        class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-400"
                                    >
                                        <Lock class="size-3" />
                                        {{ form.currency.toUpperCase() }} only on this account
                                    </span>
                                </Label>
                                <ToggleGroupRoot
                                    v-if="showCurrencyToggle"
                                    id="currency"
                                    type="single"
                                    :model-value="form.currency"
                                    class="flex h-14 items-center gap-0.5 rounded-lg border bg-muted p-[3px]"
                                    aria-label="Currency"
                                    @update:model-value="onCurrency"
                                >
                                    <ToggleGroupItem
                                        v-for="c in allowedCurrencies"
                                        :key="c.value"
                                        :value="c.value"
                                        :disabled="!c.enabled"
                                        :title="c.reason ?? undefined"
                                        class="h-full flex-1 rounded-md text-sm font-semibold text-muted-foreground transition-colors disabled:cursor-not-allowed disabled:opacity-40 data-[state=on]:bg-background data-[state=on]:text-foreground data-[state=on]:shadow-sm"
                                    >{{ c.value.toUpperCase() }}</ToggleGroupItem>
                                </ToggleGroupRoot>
                                <div
                                    v-else
                                    id="currency"
                                    class="flex h-14 items-center rounded-lg border bg-muted/50 px-3.5 text-sm"
                                    :class="allowedCurrencies.length ? 'font-semibold' : 'text-muted-foreground'"
                                >
                                    {{ allowedCurrencies[0]?.value.toUpperCase() ?? 'Set by the account' }}
                                </div>
                                <p
                                    v-for="c in allowedCurrencies.filter(x => !x.enabled && x.reason)"
                                    :key="c.value"
                                    class="text-xs text-muted-foreground"
                                >{{ c.value.toUpperCase() }}: {{ c.reason }}</p>
                                <InputError class="mt-1" :message="form.errors.currency" />
                            </div>
                        </div>
                    </section>

                    <!-- Client -->
                    <section class="mt-3 border-t px-4 pb-2 pt-4 md:px-6">
                        <h2 class="mb-3 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Client</h2>
                        <div class="grid gap-x-6 gap-y-4 md:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="client_name">Client name <span class="text-destructive">*</span></Label>
                                <Input
                                    id="client_name"
                                    v-model="form.client_name"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="Jane Smith"
                                    class="h-11 md:h-9"
                                />
                                <InputError class="mt-1" :message="form.errors.client_name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="client_email">Client email</Label>
                                <Input
                                    id="client_email"
                                    v-model="form.client_email"
                                    type="email"
                                    inputmode="email"
                                    autocomplete="off"
                                    placeholder="jane@example.com"
                                    class="h-11 md:h-9"
                                />
                                <InputError class="mt-1" :message="form.errors.client_email" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="brand_id">Brand <span class="text-destructive">*</span></Label>
                                <SearchableSelect
                                    id="brand_id"
                                    v-model="form.brand_id"
                                    required
                                    class="h-11 md:h-9"
                                    :options="props.brands"
                                    placeholder="Select a brand"
                                    search-placeholder="Search brands…"
                                />
                                <InputError class="mt-1" :message="form.errors.brand_id" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="country">Country <span class="text-destructive">*</span></Label>
                                <SearchableSelect
                                    id="country"
                                    v-model="form.country"
                                    required
                                    class="h-11 md:h-9"
                                    :options="props.countries"
                                    placeholder="Select a country"
                                    search-placeholder="Search countries…"
                                />
                                <InputError class="mt-1" :message="form.errors.country" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="relationship_manager_id">Relationship manager <span class="text-destructive">*</span></Label>
                                <SearchableSelect
                                    id="relationship_manager_id"
                                    v-model="form.relationship_manager_id"
                                    required
                                    class="h-11 md:h-9"
                                    :options="props.relationshipManagers"
                                    placeholder="Select a relationship manager"
                                    search-placeholder="Search relationship managers…"
                                />
                                <InputError class="mt-1" :message="form.errors.relationship_manager_id" />
                            </div>
                        </div>
                    </section>

                    <!-- More details: optional fields, folded away -->
                    <Collapsible v-model:open="moreOpen" class="mt-3 border-t">
                        <CollapsibleTrigger class="group flex w-full items-center gap-2 px-4 py-3.5 text-left text-sm font-medium md:px-6">
                            <ChevronRight class="size-4 transition-transform group-data-[state=open]:rotate-90" />
                            More details
                            <span class="font-normal text-muted-foreground">package, note</span>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div class="grid gap-x-6 gap-y-4 px-4 pb-4 md:grid-cols-2 md:px-6">
                                <div class="grid gap-2">
                                    <Label for="package">Package</Label>
                                    <Select v-model="form.package">
                                        <SelectTrigger id="package" class="w-full data-[size=default]:h-11 md:data-[size=default]:h-9">
                                            <SelectValue placeholder="Select a package (optional)" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="basic">Basic</SelectItem>
                                            <SelectItem value="standard">Standard</SelectItem>
                                            <SelectItem value="premium">Premium</SelectItem>
                                            <SelectItem value="platinum">Platinum</SelectItem>
                                            <SelectItem value="diamond">Diamond</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError class="mt-1" :message="form.errors.package" />
                                </div>

                                <div class="grid gap-2 md:col-span-2">
                                    <Label for="note">Note <span class="text-muted-foreground text-xs">(internal only)</span></Label>
                                    <Textarea
                                        id="note"
                                        v-model="form.note"
                                        placeholder="Optional internal notes — not shown to client"
                                        :rows="3"
                                    />
                                    <InputError class="mt-1" :message="form.errors.note" />
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </form>
            </CardContent>
        </Card>

        <!-- Action bar: pinned to the bottom with the live total on phones and tablets, a plain right aligned button on desktop -->
        <div
            class="z-20 flex items-center gap-3 border-t bg-background px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 max-lg:sticky max-lg:bottom-0 max-lg:-mx-4 max-lg:shadow-[0_-6px_16px_rgba(20,24,34,0.06)] max-md:-mb-4 md:max-lg:-mx-6 md:max-lg:-mb-6 lg:justify-end lg:border-0 lg:bg-transparent lg:px-0 lg:pt-0"
        >
            <div class="min-w-0 flex-1 lg:hidden">
                <p class="truncate text-base font-semibold tabular-nums">{{ symbol }}{{ form.amount || '0.00' }}</p>
                <p class="truncate text-xs text-muted-foreground">{{ form.client_name || 'No client yet' }} · {{ brandName ?? 'No brand yet' }}</p>
            </div>
            <Button type="submit" form="create-payment-form" class="h-11 lg:h-9" :disabled="form.processing">
                <Plus class="mr-1 size-4" />
                <span class="lg:hidden">Create and copy</span>
                <span class="max-lg:hidden">Create payment</span>
            </Button>
        </div>
    </div>
</template>
