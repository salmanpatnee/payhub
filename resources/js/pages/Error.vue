<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { AlertTriangle, ArrowLeft, Construction, CreditCard, ServerCrash, ShieldAlert, SearchX } from 'lucide-vue-next'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { home } from '@/routes'

const props = defineProps<{
    status: number
}>()

type ErrorConfig = {
    icon: typeof AlertTriangle
    circleClass: string
    iconClass: string
    title: string
    description: string
}

const configs: Record<number, ErrorConfig> = {
    404: {
        icon: SearchX,
        circleClass: 'bg-slate-100',
        iconClass: 'text-slate-500',
        title: 'Page not found',
        description: "The page you're looking for doesn't exist or may have been moved.",
    },
    403: {
        icon: ShieldAlert,
        circleClass: 'bg-amber-50',
        iconClass: 'text-amber-500',
        title: 'Access denied',
        description: "You don't have permission to view this page. Contact an administrator if you think this is a mistake.",
    },
    500: {
        icon: ServerCrash,
        circleClass: 'bg-red-50',
        iconClass: 'text-red-500',
        title: 'Something went wrong',
        description: 'An unexpected error occurred on our end. Please try again in a moment.',
    },
    503: {
        icon: Construction,
        circleClass: 'bg-blue-50',
        iconClass: 'text-blue-500',
        title: 'Down for maintenance',
        description: 'PayHub is temporarily unavailable while we perform maintenance. Please check back shortly.',
    },
}

const defaultConfig: ErrorConfig = {
    icon: AlertTriangle,
    circleClass: 'bg-slate-100',
    iconClass: 'text-slate-500',
    title: 'Unexpected error',
    description: 'Something unexpected happened.',
}

const config = computed(() => configs[props.status] ?? defaultConfig)

const page = usePage()
const isAuthenticated = computed(() => !!page.props.auth?.user)

function goBack() {
    window.history.back()
}
</script>

<template>
    <Head :title="`${config.title} — Error ${status}`" />

    <div class="error-shell">
        <div class="error-grain" />

        <div class="error-wrap">
            <Link :href="home()" class="error-logo-link">
                <CreditCard class="error-logo-icon" />
                <span class="error-logo-text">
                    <span class="error-logo-pay">Pay</span><span class="error-logo-hub">Hub</span>
                </span>
            </Link>

            <div class="error-card">
                <div class="error-card-body">
                    <Badge variant="secondary" class="error-badge">ERROR {{ status }}</Badge>

                    <div class="icon-wrap flex h-18 w-18 items-center justify-center rounded-full" :class="config.circleClass">
                        <component :is="config.icon" class="size-9" :class="config.iconClass" />
                    </div>

                    <div class="error-card-header">
                        <h1 class="error-title">{{ config.title }}</h1>
                        <p class="error-description">{{ config.description }}</p>
                    </div>

                    <div class="error-actions">
                        <Button v-if="isAuthenticated" as-child size="lg" class="w-full bg-[#F26522] text-white hover:bg-[#F26522]/90 focus-visible:ring-[#F26522]/50 font-semibold">
                            <Link :href="home()">Go to Dashboard</Link>
                        </Button>
                        <Button
                            size="lg"
                            :variant="isAuthenticated ? 'outline' : undefined"
                            class="w-full"
                            :class="!isAuthenticated && 'bg-[#F26522] text-white hover:bg-[#F26522]/90 focus-visible:ring-[#F26522]/50 font-semibold'"
                            @click="goBack"
                        >
                            <ArrowLeft class="size-4" />
                            Go back
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.error-shell {
    min-height: 100svh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    background-color: #f5f7ff;
    background-image:
        radial-gradient(ellipse 70% 50% at 50% 50%, rgba(255, 255, 255, 0.85) 0%, transparent 100%),
        radial-gradient(circle, #c7d2fe 1px, transparent 1px);
    background-size: auto, 28px 28px;
    position: relative;
    overflow: hidden;
}

.error-grain {
    position: absolute;
    inset: -100%;
    width: 300%;
    height: 300%;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='1'/%3E%3C/svg%3E");
    opacity: 0.025;
    pointer-events: none;
}

.error-wrap {
    width: 100%;
    max-width: 22rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.75rem;
    position: relative;
    z-index: 10;
}

.error-logo-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    animation: fadeDown 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.error-logo-icon {
    height: 1.75rem;
    width: 1.75rem;
    color: #f26522;
    flex-shrink: 0;
}

.error-logo-text {
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: -0.02em;
}

.error-logo-pay {
    color: #0f172a;
}

.error-logo-hub {
    color: #94a3b8;
    font-weight: 300;
}

.error-card {
    width: 100%;
    background: #ffffff;
    border-radius: 1.125rem;
    border: none;
    box-shadow:
        0 4px 6px -2px rgba(0, 0, 0, 0.04),
        0 12px 32px -8px rgba(0, 0, 0, 0.08),
        0 24px 48px -12px rgba(0, 0, 0, 0.06);
    position: relative;
    overflow: hidden;
    animation: cardIn 0.55s cubic-bezier(0.16, 1, 0.3, 1) 0.08s both;
}

.error-card-body {
    padding: 2.25rem 1.75rem 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 1rem;
    animation: contentIn 0.4s ease-out 0.2s both;
}

.error-badge {
    animation: contentIn 0.4s ease-out 0.15s both;
}

.icon-wrap {
    animation: errorBounce 0.55s cubic-bezier(0.36, 0.07, 0.19, 0.97) 0.25s both;
}

.error-card-header {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.error-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #0f172a;
    letter-spacing: -0.02em;
    line-height: 1.4;
}

.error-description {
    font-size: 0.8125rem;
    color: #64748b;
    line-height: 1.5;
}

.error-actions {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    margin-top: 0.5rem;
}

@keyframes fadeDown {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes cardIn {
    from {
        opacity: 0;
        transform: translateY(16px) scale(0.975);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes contentIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes errorBounce {
    0%   { opacity: 0; transform: scale(0.4); }
    55%  { opacity: 1; transform: scale(1.12); }
    75%  { transform: scale(0.94) rotate(-2deg); }
    90%  { transform: scale(1.03) rotate(1deg); }
    100% { transform: scale(1) rotate(0); opacity: 1; }
}
</style>
