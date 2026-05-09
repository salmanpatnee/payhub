<script setup lang="ts">
defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
        service: string | null
        package: string | null
    }
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
    stripeAccount: {
        publishable_key: string
    }
    clientSecret: string
}>()
</script>

<template>
    <div>
        <!-- ClientPayment/Pay stub — full implementation in Wave 2 -->
    </div>
</template>
