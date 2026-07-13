import { createApp, h } from 'vue';
import * as Sentry from '@sentry/vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name === 'Error':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case name.startsWith('ClientPayment/'):
                return null;  // Each ClientPayment page imports PaymentLayout directly and passes brand props
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#FF6700',
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) }).use(plugin);

        if (import.meta.env.VITE_SENTRY_DSN) {
            Sentry.init({
                app,
                dsn: import.meta.env.VITE_SENTRY_DSN as string,
                environment: import.meta.env.MODE,
                integrations: [
                    Sentry.browserTracingIntegration(),
                    Sentry.replayIntegration({ maskAllText: false, blockAllMedia: false }),
                ],
                tracesSampleRate: 0.2,
                replaysSessionSampleRate: 0.1,
                replaysOnErrorSampleRate: 1.0,
                sendDefaultPii: false,
            });
        }

        app.mount(el!);
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
