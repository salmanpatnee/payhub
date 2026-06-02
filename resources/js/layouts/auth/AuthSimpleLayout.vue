<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home } from '@/routes';
import { CreditCard } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="auth-shell">
        <div class="auth-glow" />
        <div class="auth-grain" />

        <div class="auth-wrap">
            <Link :href="home()" class="auth-logo-link">
                <CreditCard class="auth-logo-icon" />
                <span class="auth-logo-text">
                    <span class="auth-logo-pay">Pay</span><span class="auth-logo-hub">Hub</span>
                </span>
            </Link>

            <div class="auth-card">
                <div class="auth-card-body">
                    <div class="auth-card-header">
                        <h1 class="auth-title">{{ title }}</h1>
                        <p class="auth-description">{{ description }}</p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.auth-shell {
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

.auth-glow {
    display: none;
}

.auth-grain {
    position: absolute;
    inset: -100%;
    width: 300%;
    height: 300%;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='1'/%3E%3C/svg%3E");
    opacity: 0.025;
    pointer-events: none;
}

.auth-wrap {
    width: 100%;
    max-width: 22rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.75rem;
    position: relative;
    z-index: 10;
}

.auth-logo-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    animation: fadeDown 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.auth-logo-icon {
    height: 1.75rem;
    width: 1.75rem;
    color: #f26522;
    flex-shrink: 0;
}

.auth-logo-text {
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: -0.02em;
}

.auth-logo-pay {
    color: #0f172a;
}

.auth-logo-hub {
    color: #94a3b8;
    font-weight: 300;
}

.auth-card {
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


.auth-card-body {
    padding: 2rem 1.5rem 2rem;
    display: flex;
    flex-direction: column;
    gap: 1.75rem;
}

.auth-card-header {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.auth-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #0f172a;
    letter-spacing: -0.02em;
    line-height: 1.4;
}

.auth-description {
    font-size: 0.8125rem;
    color: #64748b;
    line-height: 1.5;
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
</style>
