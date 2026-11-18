<script setup lang="ts">
import FlashMessages from '@/components/FlashMessages.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { formatDate, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { type AzureToken } from '@/types/azure';
import { Head, router, useForm } from '@inertiajs/vue3';
import { KeyRound, LoaderCircle, ShieldCheck, Star, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{ tokens: AzureToken[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tokens do Azure', href: '/settings/azure-tokens' }];

const form = useForm({
    label: '',
    organization: '',
    token: '',
    expires_at: '',
    is_default: false,
});

const submit = () => {
    form.post(route('azure-tokens.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        // O PAT nunca fica no estado do formulário depois do envio.
        onFinish: () => form.reset('token'),
    });
};

const busy = ref<string | null>(null);
const action = (method: 'post' | 'patch' | 'delete', name: string, token: AzureToken) => {
    busy.value = `${name}:${token.id}`;
    router[method](route(name, { token: token.id }), {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
};

const today = new Date().toISOString().slice(0, 10);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tokens do Azure" />

        <SettingsLayout>
            <FlashMessages />

            <div class="space-y-6">
                <HeadingSmall
                    title="Tokens do Azure DevOps"
                    description="Personal Access Tokens usados para consultar o Azure em seu nome. O valor fica criptografado e nunca é exibido de novo."
                />

                <p v-if="tokens.length === 0" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Nenhum token cadastrado ainda. Adicione um abaixo para começar a usar o Azure Facilities.
                </p>

                <ul v-else class="divide-y rounded-lg border">
                    <li v-for="token in tokens" :key="token.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <KeyRound class="size-4 text-muted-foreground" />
                                <span class="font-medium">{{ token.label }}</span>
                                <span v-if="token.is_default" class="rounded bg-primary px-1.5 py-0.5 text-[11px] font-medium text-primary-foreground">padrão</span>
                                <span
                                    v-if="token.is_expired"
                                    class="rounded bg-red-100 px-1.5 py-0.5 text-[11px] font-medium text-red-700 dark:bg-red-950 dark:text-red-300"
                                    >expirado</span
                                >
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ token.organization }} · {{ token.azure_display_name ?? '—' }} ·
                                <span class="font-mono">••••{{ token.token_hint }}</span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Expira: {{ formatDate(token.expires_at) }} · Último uso: {{ timeAgo(token.last_used_at) }} · Validado:
                                {{ timeAgo(token.last_validated_at) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="busy !== null"
                                title="Testar no Azure"
                                @click="action('post', 'azure-tokens.check', token)"
                            >
                                <LoaderCircle v-if="busy === `azure-tokens.check:${token.id}`" class="animate-spin" />
                                <ShieldCheck v-else />
                                Testar
                            </Button>
                            <Button
                                v-if="!token.is_default"
                                variant="outline"
                                size="sm"
                                :disabled="busy !== null"
                                title="Tornar padrão"
                                @click="action('patch', 'azure-tokens.default', token)"
                            >
                                <Star /> Padrão
                            </Button>

                            <Dialog>
                                <DialogTrigger as-child>
                                    <Button variant="outline" size="sm" :disabled="busy !== null" aria-label="Remover token">
                                        <Trash2 class="text-red-600" />
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Remover "{{ token.label }}"?</DialogTitle>
                                        <DialogDescription>
                                            O token será apagado do Azure Facilities. Isso não revoga o PAT no Azure DevOps — faça isso em User
                                            settings → Personal access tokens se ele não for mais usado.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <DialogFooter class="gap-2">
                                        <DialogClose as-child>
                                            <Button variant="secondary">Cancelar</Button>
                                        </DialogClose>
                                        <DialogClose as-child>
                                            <Button variant="destructive" @click="action('delete', 'azure-tokens.destroy', token)">Remover</Button>
                                        </DialogClose>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="space-y-6">
                <HeadingSmall title="Adicionar token" description="O token é validado no Azure antes de ser salvo." />

                <form class="space-y-5" autocomplete="off" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="label">Nome</Label>
                        <Input id="label" v-model="form.label" maxlength="60" placeholder="Ex.: Trabalho" required />
                        <InputError :message="form.errors.label" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="organization">Organização</Label>
                        <Input id="organization" v-model="form.organization" placeholder="MinhaOrg ou https://dev.azure.com/MinhaOrg" required />
                        <InputError :message="form.errors.organization" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token">Personal Access Token</Label>
                        <Input
                            id="token"
                            v-model="form.token"
                            type="password"
                            autocomplete="new-password"
                            spellcheck="false"
                            placeholder="Cole o PAT aqui"
                            required
                        />
                        <InputError :message="form.errors.token" />
                        <p class="text-xs text-muted-foreground">
                            Escopos mínimos recomendados: <strong>Code (Read)</strong> para pull requests e
                            <strong>Work Items (Read &amp; Write)</strong> para criar tasks. Prefira validade curta.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="expires_at">Expira em (opcional)</Label>
                        <Input id="expires_at" v-model="form.expires_at" type="date" :min="today" class="w-48" />
                        <InputError :message="form.errors.expires_at" />
                        <p class="text-xs text-muted-foreground">Mesma data definida no Azure, para o app avisar quando vencer.</p>
                    </div>

                    <label v-if="tokens.length > 0" class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_default" type="checkbox" class="size-4 rounded border-input" />
                        Usar como token padrão
                    </label>

                    <Button :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="animate-spin" />
                        Validar e salvar
                    </Button>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
