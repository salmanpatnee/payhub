<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NmiAccountForm from './NmiAccountForm.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'NMI Accounts', href: '/admin/nmi-accounts' },
            { title: 'Edit account', href: '#' },
        ],
    },
});

defineProps<{
    nmiAccount: {
        id: number;
        account_name: string;
        prefix: string | null;
        environment: string;
        currency: string;
        public_tokenization_key: string;
        three_ds_enabled: boolean;
        google_pay_enabled: boolean;
        is_active: boolean;
        has_private_key: boolean;
        has_webhook_signing_key: boolean;
    };
}>();
</script>

<template>
    <Head :title="`Edit ${nmiAccount.account_name}`" />
    <NmiAccountForm :account="nmiAccount" />
</template>
