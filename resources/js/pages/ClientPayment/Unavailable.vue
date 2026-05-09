<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { CheckCircle2, XCircle, Ban } from 'lucide-vue-next'
import {
    Card, CardContent, CardHeader, CardTitle, CardDescription,
} from '@/components/ui/card'
import PaymentLayout from '@/layouts/PaymentLayout.vue'
import { computed } from 'vue'

const props = defineProps<{
    status: 'completed' | 'failed' | 'cancelled'
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
}>()

// Status-to-content map (UI-SPEC.md Unavailable.vue section — exact copy strings)
const content = computed(() => {
    const map: Record<string, { title: string; description: string; pageTitle: string; icon: string }> = {
        completed: {
            title:       'Already paid',
            description: 'This payment has already been completed. No further action is needed.',
            pageTitle:   `Already paid — ${props.brand.name}`,
            icon:        'check',
        },
        failed: {
            title:       'Payment link unavailable',
            description: 'Payment was unsuccessful. Please contact us to arrange a new payment.',
            pageTitle:   `Payment unavailable — ${props.brand.name}`,
            icon:        'x',
        },
        cancelled: {
            title:       'Link no longer active',
            description: 'This payment link has been cancelled. Please contact us if you need assistance.',
            pageTitle:   `Link no longer active — ${props.brand.name}`,
            icon:        'ban',
        },
    }
    return map[props.status] ?? map['cancelled']
})
</script>

<template>
    <PaymentLayout :brand="props.brand">
        <Head :title="content.pageTitle" />

        <Card class="rounded-xl shadow-sm text-center">
            <CardHeader>
                <!-- Status-aware icon (UI-SPEC.md Unavailable.vue) -->
                <!-- completed: CheckCircle2, green-50 -->
                <div
                    v-if="status === 'completed'"
                    class="flex h-14 w-14 items-center justify-center rounded-full bg-green-50 mx-auto"
                >
                    <CheckCircle2 class="size-7 text-green-600" />
                </div>
                <!-- failed: XCircle, red-50 -->
                <div
                    v-else-if="status === 'failed'"
                    class="flex h-14 w-14 items-center justify-center rounded-full bg-red-50 mx-auto"
                >
                    <XCircle class="size-7 text-destructive" />
                </div>
                <!-- cancelled: Ban, muted -->
                <div
                    v-else
                    class="flex h-14 w-14 items-center justify-center rounded-full bg-muted mx-auto"
                >
                    <Ban class="size-7 text-muted-foreground" />
                </div>

                <CardTitle class="text-xl">{{ content.title }}</CardTitle>
                <CardDescription>{{ content.description }}</CardDescription>
            </CardHeader>

            <!-- Empty CardContent — guard page has no actions -->
            <CardContent />
        </Card>
    </PaymentLayout>
</template>
