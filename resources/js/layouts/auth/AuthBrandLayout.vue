<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Link } from '@inertiajs/vue3';
import { ClipboardList, GitPullRequest, KeyRound } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();

const features = [
    {
        icon: GitPullRequest,
        title: 'Pull Requests',
        text: 'Os PRs em que você é autor ou revisor, de todos os projetos, atualizados sozinhos.',
    },
    {
        icon: ClipboardList,
        title: 'PBIs e Bugs',
        text: 'Seus cards com os status reais do processo, filtros e busca.',
    },
    {
        icon: KeyRound,
        title: 'Seus tokens, protegidos',
        text: 'Cada PAT é criptografado com uma chave própria, separada da aplicação.',
    },
];
</script>

<template>
    <div class="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
        <!-- Painel da marca (telas grandes) -->
        <aside class="relative hidden overflow-hidden bg-[#0b1626] text-white lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16">
            <!-- Fundo: brilho azul + grade sutil -->
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 bg-[radial-gradient(60rem_40rem_at_10%_-10%,rgba(56,149,255,0.35),transparent_60%),radial-gradient(40rem_30rem_at_110%_110%,rgba(45,212,191,0.18),transparent_60%)]"
            />
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:linear-gradient(to_right,white_1px,transparent_1px),linear-gradient(to_bottom,white_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(ellipse_at_30%_40%,black,transparent_75%)]"
            />

            <Link :href="route('home')" class="relative flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/15 backdrop-blur">
                    <AppLogoIcon class="size-6 text-sky-300" />
                </span>
                <span class="text-lg font-semibold tracking-tight">Azure Facilities</span>
            </Link>

            <div class="relative max-w-lg">
                <h2 class="text-4xl font-semibold leading-tight tracking-tight xl:text-[2.75rem]">
                    Seu dia no Azure DevOps,
                    <span class="bg-gradient-to-r from-sky-300 to-teal-200 bg-clip-text text-transparent">num só lugar.</span>
                </h2>
                <p class="mt-4 text-base text-slate-300">
                    Menos abas abertas, menos cliques: acompanhe PRs e cards de todos os projetos da organização sem sair de uma tela.
                </p>

                <ul class="mt-10 space-y-6">
                    <li v-for="feature in features" :key="feature.title" class="flex gap-4">
                        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-sky-400/10 text-sky-300 ring-1 ring-sky-300/20">
                            <component :is="feature.icon" class="size-[18px]" />
                        </span>
                        <div>
                            <p class="font-medium text-white">{{ feature.title }}</p>
                            <p class="text-sm leading-relaxed text-slate-400">{{ feature.text }}</p>
                        </div>
                    </li>
                </ul>
            </div>

            <p class="relative text-xs text-slate-500">Projeto de Extensão V · Análise e Desenvolvimento de Sistemas</p>
        </aside>

        <!-- Formulário -->
        <main class="flex items-center justify-center px-4 py-10 sm:px-8">
            <div class="w-full max-w-sm">
                <Link :href="route('home')" class="mb-10 flex items-center gap-2.5 lg:hidden">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-[#0b1626]">
                        <AppLogoIcon class="size-5 text-sky-300" />
                    </span>
                    <span class="font-semibold tracking-tight">Azure Facilities</span>
                </Link>

                <div class="mb-8 space-y-2">
                    <h1 v-if="title" class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
                    <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
                </div>

                <slot />
            </div>
        </main>
    </div>
</template>
