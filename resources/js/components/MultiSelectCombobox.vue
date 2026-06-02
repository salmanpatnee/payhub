<script setup lang="ts">
import { Check, ChevronsUpDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

type Option = { id: number; name: string };

const props = withDefaults(
    defineProps<{
        options: Option[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
    }>(),
    {
        placeholder: 'Select…',
        searchPlaceholder: 'Search…',
        emptyText: 'No results.',
    },
);

const model = defineModel<number[]>({ default: () => [] });

const open = ref(false);

const selectedLabel = computed(() => {
    if (model.value.length === 0) {
        return props.placeholder;
    }

    const names = props.options
        .filter((option) => model.value.includes(option.id))
        .map((option) => option.name);

    return names.length <= 2 ? names.join(', ') : `${names.length} selected`;
});

function toggle(id: number): void {
    model.value = model.value.includes(id)
        ? model.value.filter((value) => value !== id)
        : [...model.value, id];
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                class="w-full justify-between font-normal"
                :class="{ 'text-muted-foreground': model.length === 0 }"
            >
                <span class="truncate">{{ selectedLabel }}</span>
                <ChevronsUpDown class="ml-2 size-4 shrink-0 opacity-50" />
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-[--reka-popover-trigger-width] p-0" align="start">
            <Command>
                <CommandInput :placeholder="searchPlaceholder" />
                <CommandList>
                    <CommandEmpty>{{ emptyText }}</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="option in options"
                            :key="option.id"
                            :value="option.name"
                            @select="toggle(option.id)"
                        >
                            <Check
                                :class="cn('mr-2 size-4', model.includes(option.id) ? 'opacity-100' : 'opacity-0')"
                            />
                            {{ option.name }}
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
