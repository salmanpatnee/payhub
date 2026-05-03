<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Coming Soon', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Coming Soon" />
    <div class="flex h-full flex-1 items-center justify-center">
        <div class="text-center space-y-2">
            <p class="text-muted-foreground text-sm">This section is coming in a future update.</p>
        </div>
    </div>
</template>
