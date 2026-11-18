<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ page: number; lastPage: number; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ change: [page: number] }>();

/** Ex.: 1 … 4 5 [6] 7 8 … 20 */
const pages = computed<(number | '…')[]>(() => {
    const { page, lastPage } = props;
    const around = new Set([1, lastPage, page - 2, page - 1, page, page + 1, page + 2]);
    const list = [...around].filter((p) => p >= 1 && p <= lastPage).sort((a, b) => a - b);

    return list.flatMap((p, i) => (i > 0 && p - list[i - 1] > 1 ? (['…', p] as const) : ([p] as const)));
});

const go = (p: number) => {
    if (p !== props.page && p >= 1 && p <= props.lastPage) emit('change', p);
};
</script>

<template>
    <nav v-if="lastPage > 1" class="flex items-center justify-center gap-1" aria-label="Paginação">
        <Button variant="ghost" size="icon" :disabled="disabled || page <= 1" aria-label="Página anterior" @click="go(page - 1)">
            <ChevronLeft />
        </Button>

        <template v-for="(p, i) in pages" :key="`${p}-${i}`">
            <span v-if="p === '…'" class="px-1 text-sm text-muted-foreground">…</span>
            <Button
                v-else
                :variant="p === page ? 'default' : 'ghost'"
                size="sm"
                class="min-w-9"
                :disabled="disabled"
                :aria-current="p === page ? 'page' : undefined"
                @click="go(p)"
            >
                {{ p }}
            </Button>
        </template>

        <Button variant="ghost" size="icon" :disabled="disabled || page >= lastPage" aria-label="Próxima página" @click="go(page + 1)">
            <ChevronRight />
        </Button>
    </nav>
</template>
