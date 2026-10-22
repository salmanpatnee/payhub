import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    // Lets one build serve PayHub from the top of a domain or from a folder ("/payhub"):
    // script chunks ask window.__assetUrl() for their address at run time (see lib/basePath.ts).
    // Font addresses are fixed up by the root view (resources/views/app.blade.php).
    experimental: {
        renderBuiltUrl(filename, { hostType, ssr }) {
            if (ssr) {
                return undefined;
            }

            if (hostType === 'js') {
                return { runtime: `window.__assetUrl(${JSON.stringify(filename)})` };
            }

            return undefined;
        },
    },
    server: {
        host: '127.0.0.1',
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
