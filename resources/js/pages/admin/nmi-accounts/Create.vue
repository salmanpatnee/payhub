<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NmiAccountForm from './NmiAccountForm.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'NMI Accounts', href: '/admin/nmi-accounts' },
            { title: 'Add account', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Add NMI account" />
    <NmiAccountForm />
</template>
