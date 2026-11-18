<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type WorkItemState } from '@/types/azure';
import { ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ states: WorkItemState[] }>();

/** Nomes dos estados marcados; vazio = todos os estados do grupo. */
const selected = defineModel<string[]>({ required: true });

const label = computed(() => {
    if (selected.value.length === 0) return 'Todos os estados';
    if (selected.value.length === 1) return selected.value[0];
    return `${selected.value.length} estados`;
});

const toggle = (name: string, checked: boolean) => {
    const next = new Set(selected.value);
    if (checked) next.add(name);
    else next.delete(name);
    // Mantém a ordem do fluxo do processo.
    selected.value = props.states.map((s) => s.name).filter((n) => next.has(n));
};

// Mantém o menu aberto para marcar vários de uma vez.
const keepOpen = (e: Event) => e.preventDefault();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" class="h-9 justify-between gap-2 px-2 font-normal" aria-label="Estados">
                <span class="flex items-center gap-1.5 truncate">
                    <span
                        v-if="selected.length === 1 && states.find((s) => s.name === selected[0])?.color"
                        class="size-2 shrink-0 rounded-full"
                        :style="{ backgroundColor: `#${states.find((s) => s.name === selected[0])!.color}` }"
                        aria-hidden="true"
                    />
                    {{ label }}
                </span>
                <ChevronDown class="size-4 shrink-0 opacity-60" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Filtrar por estado</DropdownMenuLabel>
            <DropdownMenuSeparator />

            <DropdownMenuCheckboxItem
                v-for="s in states"
                :key="s.name"
                :checked="selected.includes(s.name)"
                @update:checked="(checked: boolean) => toggle(s.name, checked)"
                @select="keepOpen"
            >
                <span class="flex items-center gap-2">
                    <span
                        class="size-2 shrink-0 rounded-full border"
                        :class="{ 'border-transparent': s.color }"
                        :style="s.color ? { backgroundColor: `#${s.color}` } : undefined"
                        aria-hidden="true"
                    />
                    {{ s.name }}
                </span>
            </DropdownMenuCheckboxItem>

            <template v-if="selected.length > 0">
                <DropdownMenuSeparator />
                <DropdownMenuItem class="justify-center text-xs text-muted-foreground" @select="selected = []">Limpar seleção</DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
