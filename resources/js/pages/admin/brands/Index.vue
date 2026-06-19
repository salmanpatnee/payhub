<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import BrandPaymentPreview from '@/components/BrandPaymentPreview.vue';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';

type BrandRow = {
    id: number;
    name: string;
    slug: string;
    website_url: string | null;
    logo_url: string | null;
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

const previewTarget = ref<BrandRow | null>(null);
const previewOpen   = ref(false);

function openPreview(brand: BrandRow) {
    previewTarget.value = brand;
    previewOpen.value   = true;
}

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
            <h1 class="text-2xl font-semibold tracking-tight">Brands</h1>
            <Button as-child>
                <Link href="/admin/brands/create">
                    <Plus class="size-4 mr-1" />
                    Add brand
                </Link>
            </Button>
        </div>

        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-[#F7F5F2] border-b border-border">
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Logo</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Brand</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Website</th>
                        <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="brand in brands"
                        :key="brand.id"
                        class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                    >
                        <td class="px-5 py-3">
                            <div
                                class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border/60 shadow-xs"
                                :style="{ backgroundColor: brand.primary_color }"
                            >
                                <img
                                    v-if="brand.logo_url"
                                    :src="brand.logo_url"
                                    :alt="brand.name"
                                    class="h-full w-full object-contain p-1.5"
                                />
                                <span
                                    v-else
                                    class="text-[13px] font-semibold uppercase tracking-tight"
                                    :style="{ color: brand.primary_color }"
                                >
                                    {{ brand.name.slice(0, 2) }}
                                </span>
                            </div>
                        </td>

                        <td class="px-5 py-3.5">
                            <span class="font-medium">{{ brand.name }}</span>
                        </td>

                        <td class="px-5 py-3.5">
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

                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="cursor-pointer"
                                    :aria-label="`Preview ${brand.name}`"
                                    @click="openPreview(brand)"
                                >
                                    <Eye class="size-4" />
                                </Button>
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
                        <td colspan="4" class="px-5 py-16 text-center text-muted-foreground text-sm">
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

    <BrandPaymentPreview
        v-model:open="previewOpen"
        :name="previewTarget?.name ?? ''"
        :primary-color="previewTarget?.primary_color ?? '#000000'"
        :secondary-color="previewTarget?.secondary_color ?? '#cccccc'"
        :logo-url="previewTarget?.logo_url ?? null"
    />
</template>
