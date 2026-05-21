<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';

type RmRow = {
    id: number;
    name: string;
};

defineProps<{ rms: RmRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'RMs', href: '/admin/relationship-managers' },
        ],
    },
});

const deleteTarget = ref<RmRow | null>(null);
const deleteOpen   = ref(false);
const deleteForm   = useForm({});

function confirmDelete(rm: RmRow) {
    deleteTarget.value = rm;
    deleteOpen.value   = true;
}

function executeDelete() {
    if (!deleteTarget.value) return;
    deleteForm.delete(`/admin/relationship-managers/${deleteTarget.value.id}`, {
        onSuccess: () => {
            deleteOpen.value   = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="RMs" />

    <div class="p-6 space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight">Relationship Managers</h1>
            <Button as-child>
                <Link href="/admin/relationship-managers/create">
                    <Plus class="size-4 mr-1" />
                    Add RM
                </Link>
            </Button>
        </div>

        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-[#F7F5F2] border-b border-border">
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Name</th>
                        <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="rm in rms"
                        :key="rm.id"
                        class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                    >
                        <td class="px-5 py-3.5">
                            <span class="font-medium">{{ rm.name }}</span>
                        </td>

                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button variant="ghost" size="icon" as-child>
                                    <Link
                                        :href="`/admin/relationship-managers/${rm.id}/edit`"
                                        :aria-label="`Edit ${rm.name}`"
                                    >
                                        <Pencil class="size-4" />
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="cursor-pointer"
                                    :disabled="deleteForm.processing && deleteTarget?.id === rm.id"
                                    :aria-label="`Delete ${rm.name}`"
                                    @click="confirmDelete(rm)"
                                >
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="rms.length === 0">
                        <td colspan="2" class="px-5 py-16 text-center text-muted-foreground text-sm">
                            No relationship managers yet. Add the first one to get started.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ConfirmDeleteDialog
        v-model:open="deleteOpen"
        :title="`Delete ${deleteTarget?.name ?? 'RM'}?`"
        description="This cannot be undone. The relationship manager will be permanently removed."
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
