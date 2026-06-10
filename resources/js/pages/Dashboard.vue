<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2, CheckCircle2, Clock, Percent, TrendingUp, Wallet } from 'lucide-vue-next';
import { computed } from 'vue';
import BrandPerformanceChart from '@/components/dashboard/BrandPerformanceChart.vue';
import ConversionFunnel from '@/components/dashboard/ConversionFunnel.vue';
import CurrencyDonut from '@/components/dashboard/CurrencyDonut.vue';
import DashboardFilters from '@/components/dashboard/DashboardFilters.vue';
import InsightStrip from '@/components/dashboard/InsightStrip.vue';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import RevenueTrendChart from '@/components/dashboard/RevenueTrendChart.vue';
import RmLeaderboard from '@/components/dashboard/RmLeaderboard.vue';
import Worklist from '@/components/dashboard/Worklist.vue';
import { formatMoney } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { DashboardData, MoneyByCurrency } from '@/types/dashboard';

const props = defineProps<DashboardData>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

/** Primary currency for single-axis charts: the user's filter, else the dominant one. */
const displayCurrency = computed(() => {
    if (props.filters.currency) return props.filters.currency;
    const entries = Object.entries(props.currencySplit);
    if (entries.length === 0) return 'usd';
    return entries.sort((a, b) => b[1] - a[1])[0][0];
});

function moneyLines(amounts: MoneyByCurrency): string[] {
    const entries = Object.entries(amounts).filter(([, v]) => v > 0);
    if (entries.length === 0) return [formatMoney(0, 'usd')];
    return entries.map(([cur, cents]) => formatMoney(cents, cur));
}

const collectedLines = computed(() => moneyLines(props.kpis.collected));
const pendingLines = computed(() => moneyLines(props.kpis.pendingPipeline.amounts));
const avgLines = computed(() => moneyLines(props.kpis.avgPaymentValue));
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-4 p-4 md:p-6">
        <DashboardFilters :filters="filters" :options="filterOptions" />

        <!-- KPI cards -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
            <KpiCard
                label="Collected"
                :icon="TrendingUp"
                :value="collectedLines[0]"
                :sublines="collectedLines.slice(1)"
            />
            <KpiCard
                label="Conversion"
                :icon="Percent"
                :value="`${kpis.conversionRate}%`"
                :sublines="[`${kpis.completedCount} of ${kpis.totalCount} links`]"
                :accent="kpis.conversionRate < 60 ? 'text-amber-600' : 'text-emerald-600'"
            />
            <KpiCard
                label="Pending Pipeline"
                :icon="Clock"
                :value="pendingLines[0]"
                :sublines="[...pendingLines.slice(1), `${kpis.pendingPipeline.count} links`]"
                accent="text-amber-600"
            />
            <KpiCard
                label="Success Rate"
                :icon="CheckCircle2"
                :value="`${kpis.successRate}%`"
            />
            <KpiCard
                label="Avg Payment"
                :icon="Wallet"
                :value="avgLines[0]"
                :sublines="avgLines.slice(1)"
            />
            <KpiCard
                label="Active Brands"
                :icon="Building2"
                :value="String(kpis.activeBrands)"
            />
        </div>

        <!-- Primary charts -->
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <RevenueTrendChart :points="revenueTrend" />
            </div>
            <ConversionFunnel :funnel="funnel" />
        </div>

        <!-- Comparison row -->
        <div class="grid gap-4 lg:grid-cols-3">
            <BrandPerformanceChart :rows="brandPerformance" :currency="displayCurrency" />
            <RmLeaderboard :rows="rmLeaderboard" :currency="displayCurrency" />
            <CurrencyDonut :split="currencySplit" />
        </div>

        <!-- Insights -->
        <InsightStrip :insights="insights" />

        <!-- Worklist -->
        <div class="grid gap-4 lg:grid-cols-2">
            <Worklist
                title="Stale-Pending Links"
                subtitle="Unconverted payments — follow up to recover."
                :rows="worklist.stalePending"
                show-expiry
            />
            <Worklist
                title="High-Value Payments"
                subtitle="Largest tickets in range."
                :rows="worklist.highValue"
            />
        </div>
    </div>
</template>
