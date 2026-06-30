export type MoneyByCurrency = Record<string, number>;

export interface DashboardKpis {
    collected: MoneyByCurrency;
    conversionRate: number;
    pendingPipeline: { amounts: MoneyByCurrency; count: number };
    successRate: number;
    avgPaymentValue: MoneyByCurrency;
    activeBrands: number;
    completedCount: number;
    totalCount: number;
}

export interface RevenueTrendPoint {
    date: string;
    currency: string;
    total: number;
}

export interface DashboardFunnel {
    total: number;
    pending: number;
    completed: number;
    failed: number;
    cancelled: number;
    expired: number;
}

export interface PerformanceRow {
    id: number | null;
    name: string;
    revenue: MoneyByCurrency;
    completedCount: number;
    totalCount: number;
    conversionRate: number;
}

export interface WorklistRow {
    uuid: string;
    reference_code: number | null;
    client_name: string | null;
    brand_name: string | null;
    rm_name: string | null;
    package: string | null;
    amount: number;
    currency: string;
    status: string;
    expires_at: string | null;
    created_at: string | null;
}

export interface AccountTodayRow {
    id: number;
    name: string;
    accepted: MoneyByCurrency;
    pending: MoneyByCurrency;
}

export interface DashboardFilterValues {
    from: string | null;
    to: string | null;
    brand_id: number | null;
    relationship_manager_id: number | null;
    stripe_account_id: number | null;
    currency: string | null;
}

export interface DashboardFilterOptions {
    brands: { id: number; name: string }[];
    relationshipManagers: { id: number; name: string }[];
    stripeAccounts: { id: number; account_name: string }[];
}

export interface DashboardData {
    kpis: DashboardKpis;
    revenueTrend: RevenueTrendPoint[];
    funnel: DashboardFunnel;
    brandPerformance: PerformanceRow[];
    rmLeaderboard: PerformanceRow[];
    currencySplit: MoneyByCurrency;
    accountsToday: AccountTodayRow[];
    worklist: { stalePending: WorklistRow[]; highValue: WorklistRow[] };
    insights: string[];
    filters: DashboardFilterValues;
    filterOptions: DashboardFilterOptions;
}
