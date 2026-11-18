<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- Marca do Azure Facilities: um "A" com um nó ao centro, como um commit num fluxo. -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" :class="className" v-bind="$attrs">
        <path fill="currentColor" d="M10.2 2.6a2 2 0 0 1 3.6 0l8 17.4a1.5 1.5 0 0 1-2.7 1.3L12 5.9 4.9 21.3a1.5 1.5 0 0 1-2.7-1.3l8-17.4Z" />
        <circle fill="currentColor" cx="12" cy="16.2" r="2.6" />
    </svg>
</template>
