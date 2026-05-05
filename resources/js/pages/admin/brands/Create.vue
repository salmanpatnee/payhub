<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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

function handleLogoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) {
        form.logo = file;
        if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value);
        logoPreviewUrl.value = URL.createObjectURL(file);
    }
}

// Preview falls back to safe defaults if hex is incomplete/invalid
const HEX_RE = /^#[0-9a-fA-F]{6}$/;
const previewPrimary   = computed(() => HEX_RE.test(form.primary_color)   ? form.primary_color   : '#000000');
const previewSecondary = computed(() => HEX_RE.test(form.secondary_color) ? form.secondary_color : '#cccccc');

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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Left: form card -->
            <Card>
                <CardHeader>
                    <CardTitle>Add brand</CardTitle>
                    <CardDescription>
                        Configure the brand's name, logo, and color palette. These appear on client-facing payment pages.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form id="create-brand-form" class="space-y-4" @submit.prevent="submit">
                        <!-- Brand name -->
                        <div class="grid gap-2">
                            <Label for="name">Brand name</Label>
                            <Input id="name" v-model="form.name" type="text" required />
                            <InputError :message="form.errors.name" />
                        </div>

                        <!-- Website URL -->
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

                        <!-- Logo upload -->
                        <div class="grid gap-2">
                            <Label for="logo">Logo</Label>
                            <Input
                                id="logo"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/svg+xml"
                                @change="handleLogoChange"
                            />
                            <p class="text-xs text-muted-foreground">
                                JPG, PNG, WebP, or SVG. Max 2 MB. Optional.
                            </p>
                            <InputError :message="form.errors.logo" />
                        </div>

                        <!-- Primary color -->
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

                        <!-- Secondary color -->
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
                    </form>
                </CardContent>
                <CardFooter class="flex justify-end">
                    <Button type="submit" form="create-brand-form" :disabled="form.processing">
                        <Plus class="size-4 mr-1" />
                        Create brand
                    </Button>
                </CardFooter>
            </Card>

            <!-- Right: live preview card — all colors via :style, NOT Tailwind dynamic classes -->
            <div class="rounded-lg border border-border overflow-hidden bg-card" aria-hidden="true">
                <!-- Primary color header strip -->
                <div
                    class="h-16 flex items-center px-4 gap-3"
                    :style="{ backgroundColor: previewPrimary }"
                >
                    <div class="size-10 rounded bg-white/20 flex items-center justify-center overflow-hidden">
                        <img
                            v-if="logoPreviewUrl"
                            :src="logoPreviewUrl"
                            class="size-10 object-cover"
                            alt=""
                        />
                        <span v-else class="text-white font-semibold text-sm">
                            {{ (form.name || 'B').charAt(0).toUpperCase() }}
                        </span>
                    </div>
                    <span class="text-white font-semibold text-sm truncate">
                        {{ form.name || 'Brand name' }}
                    </span>
                </div>
                <!-- Secondary color strip -->
                <div class="h-3" :style="{ backgroundColor: previewSecondary }" />
                <!-- Preview body -->
                <div class="p-4 space-y-2">
                    <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide">Live preview</p>
                    <p class="text-sm text-muted-foreground">
                        This is how the brand header will appear on client payment pages.
                    </p>
                    <div class="flex gap-2 pt-2">
                        <span class="text-xs text-muted-foreground">Primary:</span>
                        <span class="text-xs font-mono">{{ form.primary_color || '—' }}</span>
                        <span class="text-xs text-muted-foreground ml-2">Secondary:</span>
                        <span class="text-xs font-mono">{{ form.secondary_color || '—' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
