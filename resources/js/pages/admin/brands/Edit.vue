<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check } from 'lucide-vue-next';
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

type BrandProp = {
    id: number;
    name: string;
    website_url: string | null;
    logo_url: string | null;
    primary_color: string;
    secondary_color: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
            { title: 'Edit brand', href: '#' },
        ],
    },
});

const props = defineProps<{ brand: BrandProp }>();

const form = useForm({
    _method:         'PUT',
    name:            props.brand.name,
    website_url:     props.brand.website_url ?? '',
    primary_color:   props.brand.primary_color,
    secondary_color: props.brand.secondary_color,
    logo:            null as File | null,
});

const logoPreviewUrl = ref<string | null>(props.brand.logo_url);

function handleLogoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) {
        form.logo = file;
        if (logoPreviewUrl.value && logoPreviewUrl.value.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreviewUrl.value);
        }
        logoPreviewUrl.value = URL.createObjectURL(file);
    }
}

const HEX_RE = /^#[0-9a-fA-F]{6}$/;
const previewPrimary   = computed(() => HEX_RE.test(form.primary_color)   ? form.primary_color   : '#000000');
const previewSecondary = computed(() => HEX_RE.test(form.secondary_color) ? form.secondary_color : '#cccccc');

function submit() {
    form.post(`/admin/brands/${props.brand.id}`);
}
</script>

<template>
    <Head :title="`Edit ${brand.name}`" />

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
            <Card>
                <CardHeader>
                    <CardTitle>Edit brand</CardTitle>
                    <CardDescription>
                        Update the brand's details and visual identity.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form id="edit-brand-form" class="space-y-4" @submit.prevent="submit">
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

                        <div class="grid gap-2">
                            <Label for="logo">Logo</Label>
                            <div v-if="brand.logo_url" class="mb-2">
                                <img
                                    :src="brand.logo_url"
                                    alt="Current logo"
                                    class="h-12 object-contain rounded border border-border"
                                />
                                <p class="text-xs text-muted-foreground mt-1">
                                    Current logo — upload a new file to replace it.
                                </p>
                            </div>
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
                    </form>
                </CardContent>
                <CardFooter class="flex justify-end">
                    <Button type="submit" form="edit-brand-form" :disabled="form.processing">
                        <Check class="size-4 mr-1" />
                        Save changes
                    </Button>
                </CardFooter>
            </Card>

            <div class="rounded-lg border border-border overflow-hidden bg-card" aria-hidden="true">
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
                <div class="h-3" :style="{ backgroundColor: previewSecondary }" />
                <div class="p-4 space-y-2">
                    <p class="text-xs text-muted-foreground font-semibold uppercase tracking-wide">Live preview</p>
                    <p class="text-sm text-muted-foreground">
                        This is how the brand header will appear on client payment pages.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
