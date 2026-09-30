import { AlertTriangle, HelpCircle, ShieldAlert, ShieldCheck, WifiOff } from 'lucide-vue-next';
import type { Component } from 'vue';

export type HealthStatusKey = 'healthy' | 'needs_attention' | 'restricted' | 'unreachable' | 'none';

export type HealthStatusStyle = {
    label: string;
    icon: Component;
    dot: string;
    wrap: string;
    tile: string;
    card: string;
    bar: string;
    text: string;
};

export const healthStatus: Record<HealthStatusKey, HealthStatusStyle> = {
    healthy: {
        label: 'Healthy',
        icon: ShieldCheck,
        dot: 'bg-emerald-500',
        wrap: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-400 dark:ring-emerald-800',
        tile: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400',
        card: 'from-emerald-50/70 dark:from-emerald-950/20',
        bar: 'bg-emerald-500',
        text: 'text-emerald-600 dark:text-emerald-400',
    },
    needs_attention: {
        label: 'Needs attention',
        icon: AlertTriangle,
        dot: 'bg-amber-400',
        wrap: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/50 dark:text-amber-400 dark:ring-amber-800',
        tile: 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400',
        card: 'from-amber-50/70 dark:from-amber-950/20',
        bar: 'bg-amber-400',
        text: 'text-amber-600 dark:text-amber-400',
    },
    restricted: {
        label: 'Restricted',
        icon: ShieldAlert,
        dot: 'bg-red-500',
        wrap: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/50 dark:text-red-400 dark:ring-red-800',
        tile: 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400',
        card: 'from-red-50/70 dark:from-red-950/20',
        bar: 'bg-red-500',
        text: 'text-red-600 dark:text-red-400',
    },
    unreachable: {
        label: 'Unreachable',
        icon: WifiOff,
        dot: 'bg-zinc-400',
        wrap: 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-zinc-800/50 dark:text-zinc-400 dark:ring-zinc-700',
        tile: 'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
        card: 'from-zinc-100/70 dark:from-zinc-800/20',
        bar: 'bg-zinc-400',
        text: 'text-zinc-500 dark:text-zinc-400',
    },
    none: {
        label: 'Not checked yet',
        icon: HelpCircle,
        dot: 'bg-zinc-300',
        wrap: 'bg-zinc-50 text-zinc-500 ring-zinc-200 dark:bg-zinc-900/50 dark:text-zinc-500 dark:ring-zinc-700',
        tile: 'bg-zinc-100 text-zinc-400 dark:bg-zinc-900 dark:text-zinc-500',
        card: 'from-zinc-50/70 dark:from-zinc-900/20',
        bar: 'bg-zinc-300 dark:bg-zinc-700',
        text: 'text-zinc-400 dark:text-zinc-500',
    },
};

export function statusStyle(status: string | null): HealthStatusStyle {
    return healthStatus[(status ?? 'none') as HealthStatusKey] ?? healthStatus.none;
}
