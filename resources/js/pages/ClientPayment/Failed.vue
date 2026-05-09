<script setup lang="ts">
defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
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
        <!-- ClientPayment/Failed stub — full implementation in Wave 2 -->
    </div>
</template>
