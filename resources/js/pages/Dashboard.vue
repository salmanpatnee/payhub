<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Clock, Percent, TrendingUp } from 'lucide-vue-next';
import { computed } from 'vue';
import AccountsTodayPanel from '@/components/dashboard/AccountsTodayPanel.vue';
import BrandPerformanceChart from '@/components/dashboard/BrandPerformanceChart.vue';
import ConversionFunnel from '@/components/dashboard/ConversionFunnel.vue';
import DashboardFilters from '@/components/dashboard/DashboardFilters.vue';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import RevenueTrendChart from '@/components/dashboard/RevenueTrendChart.vue';
import RmLeaderboard from '@/components/dashboard/RmLeaderboard.vue';
import { formatMoney } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { DashboardData, MoneyByCurrency } from '@/types/dashboard';

const props = defineProps<DashboardData>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

/** Stable left-to-right currency order — never inferred from backend GROUP BY row order. */
const CURRENCY_ORDER = ['usd', 'gbp'];

function moneyLines(amounts: MoneyByCurrency): string[] {
    const entries = CURRENCY_ORDER.filter((cur) => (amounts[cur] ?? 0) > 0);
    if (entries.length === 0) return [formatMoney(0, 'usd')];
    return entries.map((cur) => formatMoney(amounts[cur], cur));
}

/** Dual-currency KpiCard amounts, USD then GBP, omitting currencies with nothing to show. */
function moneyAmounts(amounts: MoneyByCurrency): { code: string; formatted: string }[] {
    const entries = CURRENCY_ORDER.filter((cur) => (amounts[cur] ?? 0) > 0);
    if (entries.length === 0) return [{ code: 'USD', formatted: formatMoney(0, 'usd') }];
    return entries.map((cur) => ({ code: cur.toUpperCase(), formatted: formatMoney(amounts[cur], cur) }));
}

const collectedAmounts = computed(() => moneyAmounts(props.kpis.collected));
const pendingAmounts = computed(() => moneyAmounts(props.kpis.pendingPipeline.amounts));
const avgLines = computed(() => moneyLines(props.kpis.avgPaymentValue));

/** Inline currency mix, e.g. "84% USD · 16% GBP" — replaces the donut. */
const currencyMix = computed(() => {
    const entries = Object.entries(props.currencySplit).filter(([, v]) => v > 0);
    const total = entries.reduce((sum, [, v]) => sum + v, 0);
    if (total === 0 || entries.length < 2) return null;
    return entries
        .sort((a, b) => b[1] - a[1])
        .map(([cur, v]) => `${Math.round((v / total) * 100)}% ${cur.toUpperCase()}`)
        .join(' · ');
});

const collectedSublines = computed(() => {
    const lines: string[] = [];
    if (currencyMix.value) lines.push(currencyMix.value);
    lines.push(`Avg ${avgLines.value.join(' · ')} / payment`);
    return lines;
});

const pendingSublines = computed(() => [`${props.kpis.pendingPipeline.count} links at risk`]);
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-6 p-4 md:p-6">
        <DashboardFilters :filters="filters" :options="filterOptions" />

        <!-- Tier 1 — the situation -->
        <div class="space-y-2">
            <div class="grid gap-3 sm:grid-cols-3">
                <KpiCard
                    hero
                    dual
                    label="Collected"
                    :icon="TrendingUp"
                    :amounts="collectedAmounts"
                    :sublines="collectedSublines"
                />
                <KpiCard
                    hero
                    dual
                    label="Pending Pipeline"
                    :icon="Clock"
                    :amounts="pendingAmounts"
                    :sublines="pendingSublines"
                    accent="text-amber-600"
                />
                <KpiCard
                    hero
                    label="Conversion"
                    :icon="Percent"
                    :value="`${kpis.conversionRate}%`"
                    :sublines="[`${kpis.completedCount} of ${kpis.totalCount} links converted`]"
                    :accent="kpis.conversionRate < 60 ? 'text-amber-600' : 'text-emerald-600'"
                />
            </div>
            <p class="px-1 text-xs text-muted-foreground">
                Across {{ kpis.activeBrands }} active
                {{ kpis.activeBrands === 1 ? 'brand' : 'brands' }} · {{ kpis.successRate }}% payment
                success rate
            </p>
        </div>

        <!-- Tier 2 — act now: trend + status + follow-ups -->
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <RevenueTrendChart :points="revenueTrend" />
            </div>
            <ConversionFunnel :funnel="funnel" />
        </div>

        <!-- Tier 3 — breakdown by brand & rep -->
        <div class="space-y-3">
            <h2 class="px-1 text-sm font-semibold">Breakdown by brand &amp; rep</h2>
            <div class="grid gap-4 lg:grid-cols-2">
                <BrandPerformanceChart :rows="brandPerformance" :currency="filters.currency ?? undefined" />
                <RmLeaderboard :rows="rmLeaderboard" :currency="filters.currency ?? undefined" />
            </div>
        </div>

        <!-- Per-account intake, driven by the same date range as the rest of the dashboard -->
        <AccountsTodayPanel :accounts="accountsToday" :filters="filters" />
    </div>
</template>
