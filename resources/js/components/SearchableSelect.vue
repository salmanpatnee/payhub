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

type Option = { id: number | string; name: string };

const props = withDefaults(
    defineProps<{
        options: Option[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        required?: boolean;
        id?: string;
        allLabel?: string;
        allValue?: string;
    }>(),
    {
        placeholder: 'Select…',
        searchPlaceholder: 'Search…',
        emptyText: 'No results.',
        required: false,
        id: undefined,
        allLabel: undefined,
        allValue: '__all',
    },
);

const model = defineModel<string>({ default: '' });

const open = ref(false);

const selectedLabel = computed(() => {
    if (props.allLabel && model.value === props.allValue) {
        return props.allLabel;
    }

    const match = props.options.find((option) => String(option.id) === model.value);

    return match ? match.name : props.placeholder;
});

const isPlaceholder = computed(
    () => selectedLabel.value === props.placeholder && !(props.allLabel && model.value === props.allValue),
);

function select(value: string): void {
    model.value = value;
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                class="relative w-full justify-between font-normal"
                :class="{ 'text-muted-foreground': isPlaceholder }"
            >
                <span class="truncate">{{ selectedLabel }}</span>
                <ChevronsUpDown class="ml-2 size-4 shrink-0 opacity-50" />
                <!-- Focusable hidden input so the browser enforces `required` on submit. -->
                <input
                    v-if="required"
                    tabindex="-1"
                    aria-hidden="true"
                    class="absolute bottom-0 left-1/2 size-0 -translate-x-1/2 opacity-0"
                    :required="required"
                    :value="model && model !== allValue ? 'selected' : ''"
                    @focus="open = true"
                >
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-[--reka-popover-trigger-width] p-0" align="start">
            <Command>
                <CommandInput :placeholder="searchPlaceholder" />
                <CommandList>
                    <CommandEmpty>{{ emptyText }}</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-if="allLabel"
                            :value="allLabel"
                            @select="select(allValue)"
                        >
                            <Check
                                :class="cn('mr-2 size-4', model === allValue ? 'opacity-100' : 'opacity-0')"
                            />
                            {{ allLabel }}
                        </CommandItem>
                        <CommandItem
                            v-for="option in options"
                            :key="option.id"
                            :value="option.name"
                            @select="select(String(option.id))"
                        >
                            <Check
                                :class="cn('mr-2 size-4', model === String(option.id) ? 'opacity-100' : 'opacity-0')"
                            />
                            {{ option.name }}
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
