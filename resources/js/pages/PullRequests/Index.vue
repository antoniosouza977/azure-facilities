<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, initials, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { type AzureToken, type PrRole, type PrStatus, type PullRequest, type PullRequestReviewer } from '@/types/azure';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    Clock,
    ExternalLink,
    GitBranch,
    GitMerge,
    GitPullRequest,
    GitPullRequestClosed,
    GitPullRequestDraft,
    KeyRound,
    RefreshCw,
    Search,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    filters: { role: PrRole; status: PrStatus; token: string | null };
    tokens: AzureToken[];
    pullRequests: PullRequest[];
    fetchedAt: string | null;
    truncated: boolean;
    limit: number;
    error: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pull Requests', href: '/pull-requests' }];

const roles: { value: PrRole; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'author', label: 'Criados por mim' },
    { value: 'reviewer', label: 'Sou revisor' },
];

const statuses: { value: PrStatus; label: string }[] = [
    { value: 'active', label: 'Ativos' },
    { value: 'completed', label: 'Concluídos' },
    { value: 'abandoned', label: 'Abandonados' },
    { value: 'all', label: 'Todos os status' },
];

// ---- filtros no servidor (consultam o Azure) ----
// Requisições do polling são assíncronas (visit.async) e não acionam o "carregando".
const loading = ref(false);
const stopStart = router.on('start', (e) => {
    if (!e.detail.visit.async) loading.value = true;
});
const stopFinish = router.on('finish', (e) => {
    if (!e.detail.visit.async) loading.value = false;
});
onBeforeUnmount(() => {
    stopStart();
    stopFinish();
});

const reload = (changes: Partial<typeof props.filters> & { fresh?: boolean }) => {
    const { fresh, ...rest } = changes;
    router.get(
        route('pull-requests.index'),
        { ...props.filters, ...rest, ...(fresh ? { fresh: 1 } : {}) },
        { preserveState: true, preserveScroll: true, replace: true, only: ['filters', 'pullRequests', 'fetchedAt', 'truncated', 'error', 'tokens'] },
    );
};

// ---- polling: busca novos PRs a cada 10s (mantém a URL/filtros atuais) ----
const POLL_INTERVAL = 10_000;
usePoll(
    POLL_INTERVAL,
    { only: ['pullRequests', 'fetchedAt', 'truncated', 'error'] },
    { autoStart: props.tokens.length > 0 },
);

// ---- filtros locais (sem nova chamada) ----
const search = ref('');
const project = ref('');
const onlyAwaiting = ref(false);

const projects = computed(() => [...new Set(props.pullRequests.map((pr) => pr.project))].sort());
const awaitingCount = computed(() => props.pullRequests.filter((pr) => pr.awaiting_my_review).length);

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.pullRequests.filter((pr) => {
        if (onlyAwaiting.value && !pr.awaiting_my_review) return false;
        if (project.value && pr.project !== project.value) return false;
        if (!term) return true;
        return [pr.title, pr.repository, pr.source_branch, pr.target_branch, pr.author, String(pr.id), String(pr.work_item?.id ?? '')].some((v) =>
            v.toLowerCase().includes(term),
        );
    });
});

// ---- apresentação ----
const voteInfo = (vote: number) =>
    ({
        10: { label: 'Aprovado', icon: Check, cls: 'bg-emerald-500 text-white' },
        5: { label: 'Aprovado com sugestões', icon: Check, cls: 'bg-emerald-500/70 text-white' },
        [-5]: { label: 'Aguardando autor', icon: Clock, cls: 'bg-amber-500 text-white' },
        [-10]: { label: 'Rejeitado', icon: X, cls: 'bg-red-600 text-white' },
    })[vote] ?? null;

const people = (pr: PullRequest): PullRequestReviewer[] =>
    [...pr.reviewers].sort((a, b) => Number(a.is_group) - Number(b.is_group) || Math.abs(b.vote) - Math.abs(a.vote));

const statusIcon = (pr: PullRequest) => {
    if (pr.status === 'completed') return { icon: GitMerge, cls: 'text-violet-600 dark:text-violet-400', label: 'Concluído' };
    if (pr.status === 'abandoned') return { icon: GitPullRequestClosed, cls: 'text-muted-foreground', label: 'Abandonado' };
    if (pr.is_draft) return { icon: GitPullRequestDraft, cls: 'text-muted-foreground', label: 'Rascunho' };
    return { icon: GitPullRequest, cls: 'text-emerald-600 dark:text-emerald-400', label: 'Ativo' };
};

const hasConflicts = (pr: PullRequest) => pr.status === 'active' && pr.merge_status === 'conflicts';
</script>

<template>
    <Head title="Pull Requests" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <!-- Sem token -->
            <div v-if="tokens.length === 0" class="mx-auto mt-10 max-w-md space-y-4 rounded-xl border p-8 text-center">
                <KeyRound class="mx-auto size-10 text-muted-foreground" />
                <h1 class="text-lg font-semibold">Conecte seu Azure DevOps</h1>
                <p class="text-sm text-muted-foreground">
                    Cadastre um Personal Access Token para listar os pull requests em que você é autor ou revisor, em todos os repositórios.
                </p>
                <Button as-child><Link :href="route('azure-tokens.index')">Adicionar token</Link></Button>
            </div>

            <template v-else>
                <!-- Cabeçalho + filtros -->
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold">Meus pull requests</h1>
                        <p class="text-sm text-muted-foreground">
                            Todos os projetos e repositórios da organização
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
                            :value="filters.status"
                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                            aria-label="Status"
                            @change="reload({ status: ($event.target as HTMLSelectElement).value as PrStatus })"
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
                        <Input v-model="search" placeholder="Filtrar por título, repositório, branch, autor, nº do PR ou card…" class="pl-8" />
                    </div>

                    <select v-if="projects.length > 1" v-model="project" class="h-9 rounded-md border border-input bg-background px-2 text-sm" aria-label="Projeto">
                        <option value="">Todos os projetos</option>
                        <option v-for="p in projects" :key="p" :value="p">{{ p }}</option>
                    </select>

                    <label v-if="awaitingCount > 0" class="flex shrink-0 items-center gap-2 text-sm">
                        <input v-model="onlyAwaiting" type="checkbox" class="size-4 rounded border-input" />
                        Aguardando meu review
                        <span class="rounded-full bg-amber-500 px-1.5 text-xs font-medium text-white">{{ awaitingCount }}</span>
                    </label>
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
                <div v-else class="overflow-hidden rounded-xl border transition-opacity" :class="{ 'opacity-60': loading }">
                    <div class="flex items-center justify-between border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                        <span>{{ visible.length }} de {{ pullRequests.length }} pull requests</span>
                        <span v-if="truncated">Mostrando os {{ limit }} mais recentes por papel</span>
                    </div>

                    <p v-if="visible.length === 0" class="p-10 text-center text-sm text-muted-foreground">
                        {{ pullRequests.length === 0 ? 'Nenhum pull request encontrado com esses filtros.' : 'Nenhum resultado para o filtro aplicado.' }}
                    </p>

                    <ul class="divide-y">
                        <li
                            v-for="pr in visible"
                            :key="pr.id"
                            class="flex gap-3 px-4 py-3 hover:bg-muted/30"
                            :class="{ 'border-l-2 border-l-amber-500': pr.awaiting_my_review }"
                        >
                            <component :is="statusIcon(pr).icon" class="mt-1 size-5 shrink-0" :class="statusIcon(pr).cls" :title="statusIcon(pr).label" />

                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <a
                                        :href="pr.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-medium hover:underline"
                                    >
                                        {{ pr.title }}
                                        <ExternalLink class="mb-0.5 inline size-3 text-muted-foreground" />
                                    </a>
                                    <span v-if="pr.is_draft" class="rounded border px-1.5 text-[11px] text-muted-foreground">rascunho</span>
                                    <span
                                        v-if="hasConflicts(pr)"
                                        class="rounded bg-red-100 px-1.5 text-[11px] font-medium text-red-700 dark:bg-red-950 dark:text-red-300"
                                        >conflitos</span
                                    >
                                    <span
                                        v-if="pr.awaiting_my_review"
                                        class="rounded bg-amber-100 px-1.5 text-[11px] font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                                        >aguardando seu review</span
                                    >
                                </div>

                                <p class="text-xs text-muted-foreground">
                                    !{{ pr.id }} · <span class="font-medium text-foreground/80">{{ pr.repository }}</span>
                                    <template v-if="projects.length > 1"> ({{ pr.project }})</template>
                                    · por {{ pr.author }} · aberto {{ timeAgo(pr.created_at) }}
                                    <template v-if="pr.closed_at"> · fechado em {{ formatDate(pr.closed_at, true) }}</template>
                                    <template v-if="pr.work_item">
                                        ·
                                        <a :href="pr.work_item.url" target="_blank" rel="noopener noreferrer" class="underline hover:text-foreground"
                                            >card #{{ pr.work_item.id }}</a
                                        >
                                    </template>
                                </p>

                                <p class="flex items-center gap-1 truncate font-mono text-xs text-muted-foreground">
                                    <GitBranch class="size-3 shrink-0" />
                                    <span class="truncate">{{ pr.source_branch }}</span>
                                    <span class="shrink-0">→</span>
                                    <span class="truncate">{{ pr.target_branch }}</span>
                                </p>
                            </div>

                            <!-- Revisores -->
                            <div class="hidden shrink-0 items-start -space-x-1.5 sm:flex">
                                <span
                                    v-for="r in people(pr).filter((x) => !x.is_group).slice(0, 6)"
                                    :key="r.id ?? r.name"
                                    class="relative flex size-7 items-center justify-center rounded-full border-2 border-background bg-muted text-[10px] font-semibold"
                                    :title="`${r.name}${r.is_required ? ' (obrigatório)' : ''}: ${voteInfo(r.vote)?.label ?? 'Sem voto'}`"
                                >
                                    {{ initials(r.name) }}
                                    <span
                                        v-if="voteInfo(r.vote)"
                                        class="absolute -bottom-1 -right-1 flex size-3.5 items-center justify-center rounded-full ring-2 ring-background"
                                        :class="voteInfo(r.vote)!.cls"
                                    >
                                        <component :is="voteInfo(r.vote)!.icon" class="size-2.5" />
                                    </span>
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
