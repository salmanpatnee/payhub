<script setup lang="ts">
defineProps<{
    payment: {
        amount: number
        currency: string
        service: string | null
    }
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
}>()
</script>

<template>
    <div>
        <!-- ClientPayment/Success stub — full implementation in Wave 2 -->
    </div>
</template>
