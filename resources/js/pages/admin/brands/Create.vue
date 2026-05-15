<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Upload, X } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
            { title: 'Add brand', href: '/admin/brands/create' },
        ],
    },
});

const form = useForm({
    name:            '',
    website_url:     '',
    primary_color:   '#000000',
    secondary_color: '#cccccc',
    logo:            null as File | null,
});

const logoPreviewUrl = ref<string | null>(null);
const logoInputRef = ref<HTMLInputElement | null>(null);

function handleLogoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) {
        form.logo = file;
        if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value);
        logoPreviewUrl.value = URL.createObjectURL(file);
    }
}

function removeLogo() {
    form.logo = null;
    if (logoInputRef.value) logoInputRef.value.value = '';
    if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value);
    logoPreviewUrl.value = null;
}

function submit() {
    // POST for create — no method spoofing needed
    form.post('/admin/brands');
}
</script>

<template>
    <Head title="Add brand" />

    <div class="p-6">
        <div class="mb-6">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link href="/admin/brands">
                    <ArrowLeft class="size-4 mr-1" />
                    Back to brands
                </Link>
            </Button>
        </div>

        <div>
            <Card>
                <CardHeader>
                    <CardTitle>Add brand</CardTitle>
                    <CardDescription>
                        Configure the brand's name, logo, and color palette. These appear on client-facing payment pages.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form id="create-brand-form" class="space-y-4" @submit.prevent="submit">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label for="name">Brand name</Label>
                                <Input id="name" v-model="form.name" type="text" required />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="website_url">Website URL</Label>
                                <Input
                                    id="website_url"
                                    v-model="form.website_url"
                                    type="url"
                                    placeholder="https://example.com"
                                />
                                <InputError :message="form.errors.website_url" />
                            </div>
                        </div>

                        <!-- Logo upload -->
                        <div class="grid gap-2">
                            <Label>Logo</Label>

                            <input
                                id="logo"
                                ref="logoInputRef"
                                type="file"
                                class="sr-only"
                                accept="image/jpeg,image/png,image/webp,image/svg+xml"
                                @change="handleLogoChange"
                            />

                            <!-- Preview state -->
                            <div v-if="logoPreviewUrl" class="group relative">
                                <div
                                    class="flex h-36 items-center justify-center overflow-hidden rounded-lg border border-border"
                                    :style="{ backgroundColor: form.primary_color }"
                                >
                                    <img
                                        :src="logoPreviewUrl"
                                        alt="Logo preview"
                                        class="max-h-28 max-w-full object-contain p-2"
                                    />
                                </div>
                                <div class="absolute inset-0 flex items-center justify-center gap-2 rounded-lg bg-black/60 opacity-0 transition-opacity group-hover:opacity-100">
                                    <label
                                        for="logo"
                                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-white/20"
                                    >
                                        <Upload class="size-3.5" />
                                        Change
                                    </label>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-red-500/80"
                                        @click="removeLogo"
                                    >
                                        <X class="size-3.5" />
                                        Remove
                                    </button>
                                </div>
                            </div>

                            <!-- Empty state -->
                            <label
                                v-else
                                for="logo"
                                class="group flex h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-border transition-all hover:border-primary/40 hover:bg-muted/30"
                            >
                                <div class="flex size-10 items-center justify-center rounded-full bg-muted transition-colors group-hover:bg-muted/60">
                                    <Upload class="size-4 text-muted-foreground" />
                                </div>
                                <div class="text-center">
                                    <p class="text-sm font-medium">Click to upload logo</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">JPG, PNG, WebP, SVG · Max 2 MB</p>
                                </div>
                            </label>

                            <InputError :message="form.errors.logo" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label>Primary color</Label>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="color"
                                        v-model="form.primary_color"
                                        class="size-9 rounded border border-input cursor-pointer p-0.5 bg-background"
                                        aria-label="Pick primary color"
                                    />
                                    <Input
                                        v-model="form.primary_color"
                                        type="text"
                                        placeholder="#000000"
                                        class="font-mono uppercase"
                                        maxlength="7"
                                    />
                                </div>
                                <InputError :message="form.errors.primary_color" />
                            </div>
                            <div class="grid gap-2">
                                <Label>Secondary color</Label>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="color"
                                        v-model="form.secondary_color"
                                        class="size-9 rounded border border-input cursor-pointer p-0.5 bg-background"
                                        aria-label="Pick secondary color"
                                    />
                                    <Input
                                        v-model="form.secondary_color"
                                        type="text"
                                        placeholder="#000000"
                                        class="font-mono uppercase"
                                        maxlength="7"
                                    />
                                </div>
                                <InputError :message="form.errors.secondary_color" />
                            </div>
                        </div>
                    </form>
                </CardContent>
                <CardFooter class="flex justify-end">
                    <Button type="submit" form="create-brand-form" :disabled="form.processing">
                        <Plus class="size-4 mr-1" />
                        Create brand
                    </Button>
                </CardFooter>
            </Card>

        </div>
    </div>
</template>
