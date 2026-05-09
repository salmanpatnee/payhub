<script setup lang="ts">
defineProps<{
    status: string
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
        <!-- ClientPayment/Unavailable stub — full implementation in Wave 2 -->
    </div>
</template>
