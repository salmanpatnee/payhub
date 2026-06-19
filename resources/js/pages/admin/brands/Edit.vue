<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Eye, Upload, X } from 'lucide-vue-next';
import { ref } from 'vue';
import BrandPaymentPreview from '@/components/BrandPaymentPreview.vue';
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
    secondary_color: string | null;
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
    secondary_color: props.brand.secondary_color ?? '',
    logo:            null as File | null,
});

const logoPreviewUrl = ref<string | null>(props.brand.logo_url);
const logoInputRef = ref<HTMLInputElement | null>(null);
const previewOpen = ref(false);

function handleLogoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) {
        form.logo = file;
        if (logoPreviewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(logoPreviewUrl.value);
        logoPreviewUrl.value = URL.createObjectURL(file);
    }
}

function removeLogo() {
    form.logo = null;
    if (logoInputRef.value) logoInputRef.value.value = '';
    if (logoPreviewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(logoPreviewUrl.value);
    logoPreviewUrl.value = props.brand.logo_url;
}

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

        <div>
            <Card>
                <CardHeader>
                    <CardTitle>Edit brand</CardTitle>
                    <CardDescription>
                        Update the brand's details and visual identity.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form id="edit-brand-form" class="space-y-4" @submit.prevent="submit">
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

                            <!-- Preview state (existing or newly selected) -->
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
                                        v-if="form.logo"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-red-500/80"
                                        @click="removeLogo"
                                    >
                                        <X class="size-3.5" />
                                        Revert
                                    </button>
                                </div>
                            </div>

                            <!-- Empty state (brand has no logo) -->
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
                            <Label>Pay button color</Label>
                            <p class="text-xs text-muted-foreground -mt-1">
                                Overrides the primary color on the pay button. Leave empty to use the primary color.
                            </p>

                            <div v-if="form.secondary_color" class="flex items-center gap-2">
                                <input
                                    type="color"
                                    v-model="form.secondary_color"
                                    class="size-9 rounded border border-input cursor-pointer p-0.5 bg-background"
                                    aria-label="Pick pay button color"
                                />
                                <Input
                                    v-model="form.secondary_color"
                                    type="text"
                                    placeholder="#000000"
                                    class="font-mono uppercase"
                                    maxlength="7"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="shrink-0 text-muted-foreground hover:text-foreground"
                                    aria-label="Clear button color"
                                    @click="form.secondary_color = ''"
                                >
                                    <X class="size-4" />
                                </Button>
                            </div>

                            <div v-else class="flex items-center gap-2">
                                <span class="text-sm text-muted-foreground italic flex-1">Using primary color</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="form.secondary_color = form.primary_color"
                                >
                                    Customize
                                </Button>
                            </div>

                            <InputError :message="form.errors.secondary_color" />
                        </div>
                    </form>
                </CardContent>
                <CardFooter class="flex justify-end gap-3">
                    <Button
                        type="button"
                        class="bg-black text-white hover:bg-black/90"
                        @click="previewOpen = true"
                    >
                        <Eye class="size-4 mr-1.5" />
                        Preview payment page
                    </Button>
                    <Button type="submit" form="edit-brand-form" :disabled="form.processing">
                        <Check class="size-4 mr-1" />
                        Save changes
                    </Button>
                </CardFooter>
            </Card>

        </div>

        <BrandPaymentPreview
            v-model:open="previewOpen"
            :name="form.name"
            :primary-color="form.primary_color"
            :secondary-color="form.secondary_color"
            :logo-url="logoPreviewUrl"
        />
    </div>
</template>
