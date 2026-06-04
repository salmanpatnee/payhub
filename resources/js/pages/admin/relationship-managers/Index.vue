<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Pencil, Plus, Power, PowerOff, Search, Trash2, XCircle } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConfirmDeleteDialog from '@/components/ConfirmDeleteDialog.vue';
import { index as rmsIndex } from '@/actions/App/Http/Controllers/Admin/RelationshipManagerController';

type RmRow = {
    id: number;
    name: string;
    is_active: boolean;
};

type PaginatedRms = {
    data: RmRow[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    from: number | null;
    to: number | null;
};

const props = defineProps<{
    rms: PaginatedRms;
    filters: { search?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'RMs', href: '/admin/relationship-managers' },
        ],
    },
});

const filters = reactive({ search: props.filters.search || '' });

watch(
    filters,
    (f) => {
        router.get(
            rmsIndex.url({ query: f.search ? { search: f.search } : {} }),
            {},
            { preserveState: true, replace: true },
        );
    },
    { deep: true },
);

const pageItems = computed((): (number | '...')[] => {
    const current = props.rms.current_page;
    const last = props.rms.last_page;
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const items: (number | '...')[] = [1];
    if (current > 3) items.push('...');
    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);
    for (let i = start; i <= end; i++) items.push(i);
    if (current < last - 2) items.push('...');
    items.push(last);
    return items;
});

function goToPage(page: number): void {
    const query: Record<string, string | number> = { page };
    if (filters.search) query.search = filters.search;
    router.get(
        rmsIndex.url({ query }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const deactivateTarget = ref<RmRow | null>(null);
const deactivateOpen   = ref(false);
const deactivateForm   = useForm({});

const activateTarget = ref<RmRow | null>(null);
const activateOpen   = ref(false);
const activateForm   = useForm({});

const deleteTarget = ref<RmRow | null>(null);
const deleteOpen   = ref(false);
const deleteForm   = useForm({});

function confirmActivate(rm: RmRow) {
    activateTarget.value = rm;
    activateOpen.value   = true;
}

function executeActivate() {
    if (!activateTarget.value) return;
    activateForm.patch(`/admin/relationship-managers/${activateTarget.value.id}/activate`, {
        onSuccess: () => {
            activateOpen.value   = false;
            activateTarget.value = null;
        },
    });
}

function confirmDeactivate(rm: RmRow) {
    deactivateTarget.value = rm;
    deactivateOpen.value   = true;
}

function executeDeactivate() {
    if (!deactivateTarget.value) return;
    deactivateForm.patch(`/admin/relationship-managers/${deactivateTarget.value.id}/deactivate`, {
        onSuccess: () => {
            deactivateOpen.value   = false;
            deactivateTarget.value = null;
        },
    });
}

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

    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight">Relationship Managers</h1>
            <Button as-child>
                <Link href="/admin/relationship-managers/create">
                    <Plus class="size-4 mr-1" />
                    Add RM
                </Link>
            </Button>
        </div>

        <!-- Search -->
        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <div class="flex gap-4 p-4">
                <div class="flex flex-col gap-1.5 flex-1 min-w-0">
                    <Label class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Search</Label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            v-model="filters.search"
                            type="search"
                            placeholder="Name…"
                            class="pl-8"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border/70 bg-card shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-[#F7F5F2] border-b border-border">
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">S.No</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Name</th>
                        <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Status</th>
                        <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(rm, index) in rms.data"
                        :key="rm.id"
                        class="border-b border-border/50 last:border-0 hover:bg-muted/40 transition-colors duration-150"
                    >
                        <td class="px-5 py-3.5 text-muted-foreground tabular-nums">{{ (rms.from ?? 1) + index }}</td>
                        <td class="px-5 py-3.5">
                            <span class="font-medium">{{ rm.name }}</span>
                        </td>
                        <td class="px-5 py-3.5">
                            <div v-if="rm.is_active" class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600 dark:text-green-500">
                                <CheckCircle2 class="size-4" />
                                Active
                            </div>
                            <div v-else class="inline-flex items-center gap-1.5 text-sm font-medium text-red-500 dark:text-red-400">
                                <XCircle class="size-4" />
                                Inactive
                            </div>
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
                                    v-if="rm.is_active"
                                    variant="ghost"
                                    size="icon"
                                    class="cursor-pointer"
                                    :aria-label="`Deactivate ${rm.name}`"
                                    @click="confirmDeactivate(rm)"
                                >
                                    <PowerOff class="size-4 text-destructive" />
                                </Button>
                                <Button
                                    v-if="!rm.is_active"
                                    variant="ghost"
                                    size="icon"
                                    class="cursor-pointer"
                                    :aria-label="`Activate ${rm.name}`"
                                    @click="confirmActivate(rm)"
                                >
                                    <Power class="size-4 text-green-600" />
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

                    <tr v-if="rms.data.length === 0">
                        <td colspan="4" class="px-5 py-16 text-center text-muted-foreground text-sm">
                            No relationship managers yet. Add the first one to get started.
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="rms.last_page > 1" class="flex items-center justify-center border-t border-border/50 px-5 py-3.5">
                <!-- Centered page navigation -->
                <nav class="flex items-center gap-0.5" aria-label="Pagination">
                    <!-- First & Prev -->
                    <button
                        :disabled="rms.current_page === 1"
                        class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                        title="First page"
                        @click="goToPage(1)"
                    >«</button>
                    <button
                        :disabled="rms.current_page === 1"
                        class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                        title="Previous page"
                        @click="goToPage(rms.current_page - 1)"
                    >‹</button>

                    <!-- Numbered pages + ellipsis -->
                    <div class="mx-1 flex items-center gap-0.5">
                        <template v-for="(item, i) in pageItems" :key="i">
                            <span
                                v-if="item === '...'"
                                class="flex h-8 w-6 select-none items-end justify-center pb-1 text-[10px] tracking-widest text-muted-foreground/40"
                            >···</span>
                            <button
                                v-else
                                :class="[
                                    'relative flex h-8 w-8 items-center justify-center rounded text-xs font-medium tabular-nums transition-all duration-150',
                                    item === rms.current_page
                                        ? 'bg-primary text-primary-foreground shadow-sm scale-105'
                                        : 'text-foreground/60 hover:bg-muted hover:text-foreground',
                                ]"
                                @click="goToPage(item as number)"
                            >{{ item }}</button>
                        </template>
                    </div>

                    <!-- Next & Last -->
                    <button
                        :disabled="rms.current_page === rms.last_page"
                        class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                        title="Next page"
                        @click="goToPage(rms.current_page + 1)"
                    >›</button>
                    <button
                        :disabled="rms.current_page === rms.last_page"
                        class="flex h-8 w-7 items-center justify-center rounded text-base leading-none text-muted-foreground transition-all hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                        title="Last page"
                        @click="goToPage(rms.last_page)"
                    >»</button>
                </nav>
            </div>
        </div>
    </div>

    <Dialog v-model:open="deactivateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Deactivate RM?</DialogTitle>
                <DialogDescription>
                    {{ deactivateTarget?.name }} will be hidden from selection dropdowns for new
                    users and payments. Existing assignments are preserved.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deactivateOpen = false">Keep active</Button>
                <Button
                    variant="destructive"
                    :disabled="deactivateForm.processing"
                    @click="executeDeactivate"
                >
                    Deactivate
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="activateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Activate RM?</DialogTitle>
                <DialogDescription>
                    {{ activateTarget?.name }} will become available for selection again.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="activateOpen = false">Cancel</Button>
                <Button
                    :disabled="activateForm.processing"
                    @click="executeActivate"
                >
                    Activate
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDeleteDialog
        v-model:open="deleteOpen"
        :title="`Delete ${deleteTarget?.name ?? 'RM'}?`"
        description="This cannot be undone. The relationship manager will be permanently removed."
        :processing="deleteForm.processing"
        @confirm="executeDelete"
    />
</template>
