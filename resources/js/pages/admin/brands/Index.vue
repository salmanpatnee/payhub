<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CreditCard, Pencil, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

type BrandRow = {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
    primary_color: string;
    secondary_color: string;
    stripe_accounts_count: number;
};

defineProps<{ brands: BrandRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
        ],
    },
});
</script>

<template>
    <Head title="Brands" />

    <div class="p-6 space-y-6">
        <!-- Page header -->
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Brands</h1>
            <Button as-child>
                <Link href="/admin/brands/create">
                    <Plus class="size-4 mr-1" />
                    Add brand
                </Link>
            </Button>
        </div>

        <!-- Brand table -->
        <div class="rounded-lg border border-border bg-card overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-muted/40 border-b border-border">
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Brand</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Colors</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Stripe Accounts</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="brand in brands"
                        :key="brand.id"
                        class="border-b border-border last:border-0 hover:bg-muted/50 transition-colors"
                    >
                        <!-- Brand name + logo -->
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img
                                    v-if="brand.logo_url"
                                    :src="brand.logo_url"
                                    class="size-8 rounded object-cover border border-border"
                                    alt=""
                                />
                                <span
                                    v-else
                                    class="size-8 rounded bg-muted flex items-center justify-center text-muted-foreground text-xs font-semibold"
                                >
                                    {{ brand.name.charAt(0).toUpperCase() }}
                                </span>
                                <span class="font-medium">{{ brand.name }}</span>
                            </div>
                        </td>

                        <!-- Color swatches — :style binding, NOT dynamic Tailwind classes -->
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span
                                    aria-hidden="true"
                                    class="size-5 rounded-sm border border-border inline-block"
                                    :style="{ backgroundColor: brand.primary_color }"
                                    :title="`Primary: ${brand.primary_color}`"
                                />
                                <span
                                    aria-hidden="true"
                                    class="size-5 rounded-sm border border-border inline-block"
                                    :style="{ backgroundColor: brand.secondary_color }"
                                    :title="`Secondary: ${brand.secondary_color}`"
                                />
                            </div>
                        </td>

                        <!-- Stripe account count -->
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ brand.stripe_accounts_count }}
                            {{ brand.stripe_accounts_count !== 1 ? 'accounts' : 'account' }}
                        </td>

                        <!-- Actions -->
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button variant="ghost" size="icon" as-child>
                                    <Link
                                        :href="`/admin/brands/${brand.id}/stripe-accounts`"
                                        :aria-label="`View Stripe accounts for ${brand.name}`"
                                    >
                                        <CreditCard class="size-4" />
                                    </Link>
                                </Button>
                                <Button variant="ghost" size="icon" as-child>
                                    <Link
                                        :href="`/admin/brands/${brand.id}/edit`"
                                        :aria-label="`Edit ${brand.name}`"
                                    >
                                        <Pencil class="size-4" />
                                    </Link>
                                </Button>
                            </div>
                        </td>
                    </tr>

                    <!-- Empty state -->
                    <tr v-if="brands.length === 0">
                        <td colspan="4" class="px-4 py-12 text-center text-muted-foreground text-sm">
                            No brands yet. Add your first brand to get started.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
