<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';

type BrandRow = {
    id: number;
    name: string;
    slug: string;
    website_url: string | null;
    primary_color: string;
    secondary_color: string;
};

defineProps<{ brands: BrandRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
        ],
    },
});

const deleteTarget = ref<BrandRow | null>(null);
const deleteOpen   = ref(false);
const deleteForm   = useForm({});

function confirmDelete(brand: BrandRow) {
    deleteTarget.value = brand;
    deleteOpen.value   = true;
}

function executeDelete() {
    if (!deleteTarget.value) return;
    deleteForm.delete(`/admin/brands/${deleteTarget.value.id}`, {
        onSuccess: () => {
            deleteOpen.value   = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="Brands" />

    <div class="p-6 space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Brands</h1>
            <Button as-child>
                <Link href="/admin/brands/create">
                    <Plus class="size-4 mr-1" />
                    Add brand
                </Link>
            </Button>
        </div>

        <div class="rounded-lg border border-border bg-card overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-muted/40 border-b border-border">
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Brand</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Website</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Colors</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="brand in brands"
                        :key="brand.id"
                        class="border-b border-border last:border-0 hover:bg-muted/50 transition-colors"
                    >
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ brand.name }}</span>
                        </td>

                        <td class="px-4 py-3">
                            <a
                                v-if="brand.website_url"
                                :href="brand.website_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-xs text-muted-foreground hover:underline truncate max-w-[200px] inline-block"
                            >
                                {{ brand.website_url }}
                            </a>
                            <span v-else class="text-xs text-muted-foreground">—</span>
                        </td>

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

                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button variant="ghost" size="icon" as-child>
                                    <Link
                                        :href="`/admin/brands/${brand.id}/edit`"
                                        :aria-label="`Edit ${brand.name}`"
                                    >
                                        <Pencil class="size-4" />
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="cursor-pointer"
                                    :disabled="deleteForm.processing && deleteTarget?.id === brand.id"
                                    :aria-label="`Delete ${brand.name}`"
                                    @click="confirmDelete(brand)"
                                >
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="brands.length === 0">
                        <td colspan="4" class="px-4 py-12 text-center text-muted-foreground text-sm">
                            No brands yet. Add your first brand to get started.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ConfirmDeleteDialog
        v-model:open="deleteOpen"
        :title="`Delete ${deleteTarget?.name ?? 'brand'}?`"
        description="This cannot be undone. All brand data will be permanently removed."
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
