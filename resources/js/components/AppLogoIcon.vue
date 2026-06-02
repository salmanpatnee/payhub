<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { CreditCard } from 'lucide-vue-next';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <CreditCard :class="className" v-bind="$attrs" />
</template>
