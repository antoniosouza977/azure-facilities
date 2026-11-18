<script setup lang="ts">
import Pagination from '@/components/Pagination.vue';
import WorkItemStateFilter from '@/components/WorkItemStateFilter.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import {
    type AzureToken,
    type WorkItem,
    type WorkItemRole,
    type WorkItemState,
    type WorkItemStateCategory,
    type WorkItemStatus,
    type WorkItemTypeFilter,
} from '@/types/azure';
import { Head, Link, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { AlertTriangle, Bug, ClipboardList, ExternalLink, KeyRound, RefreshCw, Search, Tag } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

type Filters = {
    role: WorkItemRole;
    type: WorkItemTypeFilter;
    status: WorkItemStatus;
    state: string[];
    project: string | null;
    q: string | null;
    token: string | null;
};

const props = defineProps<{
    filters: Filters;
    tokens: AzureToken[];
    workItems: WorkItem[];
    pagination: { page: number; per_page: number; total: number; last_page: number };
    states: WorkItemState[];
    projects: string[];
    fetchedAt: string | null;
    truncated: boolean;
    limit: number;
    error: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'PBIs e Bugs', href: '/work-items' }];

const roles: { value: WorkItemRole; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'assigned', label: 'Atribuídos a mim' },
    { value: 'created', label: 'Criados por mim' },
];

const types: { value: WorkItemTypeFilter; label: string }[] = [
    { value: 'all', label: 'PBIs e Bugs' },
    { value: 'pbi', label: 'Somente PBIs' },
    { value: 'bug', label: 'Somente Bugs' },
];

const statuses: { value: WorkItemStatus; label: string }[] = [
    { value: 'open', label: 'Em aberto' },
    { value: 'done', label: 'Concluídos' },
    { value: 'removed', label: 'Removidos' },
    { value: 'all', label: 'Todos os status' },
];

// ---- todos os filtros e a página são resolvidos no servidor (WIQL no Azure) ----
const loading = ref(false);
const stopStart = router.on('start', () => (loading.value = true));
const stopFinish = router.on('finish', () => (loading.value = false));
onBeforeUnmount(() => {
    stopStart();
    stopFinish();
});

const listTop = ref<HTMLElement | null>(null);

const reload = (changes: Partial<Filters> & { fresh?: boolean; page?: number }) => {
    const { fresh, page, ...rest } = changes;
    // Trocar de grupo de status pode invalidar o estado exato escolhido;
    // trocar de token (outra organização) invalida o projeto.
    const changingToken = 'token' in rest;
    const state = 'status' in rest ? [] : props.filters.state;
    const project = changingToken ? null : props.filters.project;
    const params = { ...props.filters, state, project, ...rest, ...(page && page > 1 ? { page } : {}), ...(fresh ? { fresh: 1 } : {}) };

    router.get(
        route('work-items.index'),
        // Sem parâmetros vazios na URL (inclusive listas vazias).
        Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))),
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: [
                'filters',
                'workItems',
                'pagination',
                'states',
                'fetchedAt',
                'truncated',
                'error',
                'tokens',
                ...(changingToken || fresh ? ['projects'] : []),
            ],
            // Ao trocar de página, volta para o topo da lista.
            onSuccess: () => page && listTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
        },
    );
};

// Qualquer filtro novo volta para a página 1 (page omitido).
const search = ref(props.filters.q ?? '');
watchDebounced(
    search,
    (value) => {
        if (value.trim() !== (props.filters.q ?? '')) reload({ q: value.trim() || null });
    },
    { debounce: 400 },
);

// Multiselect de estados: espera o usuário terminar de marcar antes de consultar.
const selectedStates = ref<string[]>([...props.filters.state]);
const sameStates = (a: string[], b: string[]) => a.length === b.length && a.every((v, i) => v === b[i]);
watchDebounced(
    selectedStates,
    (value) => {
        if (!sameStates(value, props.filters.state)) reload({ state: value });
    },
    { debounce: 500 },
);
// O servidor pode descartar estados (ex.: ao trocar o grupo de status): reflete na UI.
watch(
    () => props.filters.state,
    (value) => {
        if (!sameStates(value, selectedStates.value)) selectedStates.value = [...value];
    },
);

// ---- apresentação ----
const isBug = (wi: WorkItem) => wi.type === 'Bug';

// Cor de fundo pela categoria do Azure; o ponto colorido usa a cor exata do estado no processo.
const stateCls: Record<WorkItemStateCategory, string> = {
    proposed: 'bg-muted text-muted-foreground',
    in_progress: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    resolved: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
    completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    removed: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <Head title="PBIs e Bugs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <!-- Sem token -->
            <div v-if="tokens.length === 0" class="mx-auto mt-10 max-w-md space-y-4 rounded-xl border p-8 text-center">
                <KeyRound class="mx-auto size-10 text-muted-foreground" />
                <h1 class="text-lg font-semibold">Conecte seu Azure DevOps</h1>
                <p class="text-sm text-muted-foreground">
                    Cadastre um Personal Access Token (com escopo Work Items: Read) para listar os PBIs e Bugs atribuídos a você ou criados por você.
                </p>
                <Button as-child><Link :href="route('azure-tokens.index')">Adicionar token</Link></Button>
            </div>

            <template v-else>
                <!-- Cabeçalho + filtros -->
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold">Meus PBIs e Bugs</h1>
                        <p class="text-sm text-muted-foreground">
                            Todos os projetos da organização
                            <template v-if="fetchedAt"> · atualizado {{ timeAgo(fetchedAt) }}</template>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            v-if="tokens.length > 1"
                            :value="filters.token ?? ''"
                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                            aria-label="Token"
                            @change="reload({ token: ($event.target as HTMLSelectElement).value })"
                        >
                            <option v-for="t in tokens" :key="t.id" :value="t.id">{{ t.label }} ({{ t.organization }})</option>
                        </select>

                        <select
                            :value="filters.type"
                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                            aria-label="Tipo de item"
                            @change="reload({ type: ($event.target as HTMLSelectElement).value as WorkItemTypeFilter })"
                        >
                            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>

                        <select
                            :value="filters.status"
                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                            aria-label="Status"
                            @change="reload({ status: ($event.target as HTMLSelectElement).value as WorkItemStatus })"
                        >
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>

                        <Button variant="outline" size="icon" :disabled="loading" title="Atualizar agora" @click="reload({ fresh: true })">
                            <RefreshCw :class="{ 'animate-spin': loading }" />
                        </Button>
                    </div>
                </div>

                <div class="flex flex-col gap-3 md:flex-row md:items-center">
                    <div class="inline-flex rounded-lg bg-muted p-1" role="tablist">
                        <button
                            v-for="r in roles"
                            :key="r.value"
                            role="tab"
                            :aria-selected="filters.role === r.value"
                            class="rounded-md px-3 py-1.5 text-sm transition-colors"
                            :class="filters.role === r.value ? 'bg-background font-medium shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                            @click="reload({ role: r.value })"
                        >
                            {{ r.label }}
                        </button>
                    </div>

                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input v-model="search" placeholder="Buscar por título, tag ou nº do card…" class="pl-8" />
                    </div>

                    <WorkItemStateFilter v-if="states.length > 1" v-model="selectedStates" :states="states" />

                    <select
                        v-if="projects.length > 1"
                        :value="filters.project ?? ''"
                        @change="reload({ project: ($event.target as HTMLSelectElement).value || null })"
                        class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                        aria-label="Projeto"
                    >
                        <option value="">Todos os projetos</option>
                        <option v-for="p in projects" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>

                <!-- Erro -->
                <div
                    v-if="error"
                    class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    <div>
                        {{ error }}
                        <Link :href="route('azure-tokens.index')" class="ml-1 underline">Gerenciar tokens</Link>
                    </div>
                </div>

                <!-- Lista -->
                <div v-else ref="listTop" class="scroll-mt-4 overflow-hidden rounded-xl border transition-opacity" :class="{ 'opacity-60': loading }">
                    <div class="flex items-center justify-between border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                        <span v-if="pagination.total > 0">
                            {{ (pagination.page - 1) * pagination.per_page + 1 }}–{{
                                (pagination.page - 1) * pagination.per_page + workItems.length
                            }}
                            de {{ pagination.total }} itens
                        </span>
                        <span v-else>0 itens</span>
                        <span v-if="truncated">Limitado aos {{ limit }} alterados mais recentemente</span>
                    </div>

                    <p v-if="workItems.length === 0" class="p-10 text-center text-sm text-muted-foreground">
                        Nenhum PBI ou Bug encontrado com esses filtros.
                    </p>

                    <ul class="divide-y">
                        <li v-for="wi in workItems" :key="wi.id" class="flex gap-3 px-4 py-3 hover:bg-muted/30">
                            <component
                                :is="isBug(wi) ? Bug : ClipboardList"
                                class="mt-1 size-5 shrink-0"
                                :class="isBug(wi) ? 'text-red-600 dark:text-red-400' : 'text-sky-600 dark:text-sky-400'"
                                :title="wi.type"
                            />

                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <a :href="wi.url" target="_blank" rel="noopener noreferrer" class="font-medium hover:underline">
                                        {{ wi.title }}
                                        <ExternalLink class="mb-0.5 inline size-3 text-muted-foreground" />
                                    </a>
                                    <span
                                        class="inline-flex items-center gap-1 rounded px-1.5 text-[11px] font-medium"
                                        :class="stateCls[wi.state_category]"
                                    >
                                        <span
                                            v-if="wi.state_color"
                                            class="size-2 rounded-full"
                                            :style="{ backgroundColor: `#${wi.state_color}` }"
                                            aria-hidden="true"
                                        />
                                        {{ wi.state }}
                                    </span>
                                    <span
                                        v-if="wi.priority !== null && wi.priority <= 1"
                                        class="rounded bg-red-100 px-1.5 text-[11px] font-medium text-red-700 dark:bg-red-950 dark:text-red-300"
                                        >prioridade {{ wi.priority }}</span
                                    >
                                </div>

                                <p class="text-xs text-muted-foreground">
                                    {{ isBug(wi) ? 'Bug' : 'PBI' }} #{{ wi.id }} ·
                                    <span class="font-medium text-foreground/80">{{ wi.project }}</span>
                                    <template v-if="wi.iteration"> · {{ wi.iteration }}</template>
                                    <template v-if="wi.assigned_to"> · com {{ wi.assigned_to }}</template>
                                    <template v-else> · sem responsável</template>
                                    <template v-if="!wi.roles.includes('created') && wi.created_by"> · criado por {{ wi.created_by }}</template>
                                    <template v-if="wi.changed_at"> · alterado {{ timeAgo(wi.changed_at) }}</template>
                                </p>

                                <p v-if="wi.tags.length" class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                    <Tag class="size-3 shrink-0" />
                                    <span v-for="t in wi.tags" :key="t" class="rounded border px-1.5">{{ t }}</span>
                                </p>
                            </div>

                            <div class="hidden shrink-0 flex-col items-end gap-1 sm:flex">
                                <span v-if="wi.roles.includes('assigned')" class="rounded bg-muted px-1.5 text-[11px] text-muted-foreground"
                                    >atribuído a mim</span
                                >
                                <span v-if="wi.roles.includes('created')" class="rounded bg-muted px-1.5 text-[11px] text-muted-foreground"
                                    >criado por mim</span
                                >
                            </div>
                        </li>
                    </ul>

                    <div v-if="pagination.last_page > 1" class="border-t px-4 py-2">
                        <Pagination
                            :page="pagination.page"
                            :last-page="pagination.last_page"
                            :disabled="loading"
                            @change="(p) => reload({ page: p })"
                        />
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
