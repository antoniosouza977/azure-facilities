<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle, Eye, EyeOff, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Entrar" description="Use seu email corporativo e senha para acessar.">
        <Head title="Entrar" />

        <div
            v-if="status"
            class="mb-6 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300"
        >
            <CheckCircle class="mt-0.5 size-4 shrink-0" />
            <span>{{ status }}</span>
        </div>

        <form @submit.prevent="submit" class="grid gap-5">
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    v-model="form.email"
                    placeholder="voce@infortechms.com.br"
                    class="h-11"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Senha</Label>
                    <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm"> Esqueceu a senha? </TextLink>
                </div>
                <div class="relative">
                    <Input
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        required
                        autocomplete="current-password"
                        v-model="form.password"
                        placeholder="Sua senha"
                        class="h-11 pr-11"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'"
                        :aria-pressed="showPassword"
                        @click="showPassword = !showPassword"
                    >
                        <EyeOff v-if="showPassword" class="size-4" />
                        <Eye v-else class="size-4" />
                    </button>
                </div>
                <InputError :message="form.errors.password" />
            </div>

            <Label for="remember" class="flex w-fit cursor-pointer items-center gap-3 font-normal">
                <Checkbox id="remember" v-model:checked="form.remember" />
                <span>Manter conectado</span>
            </Label>

            <Button type="submit" class="mt-1 h-11 w-full" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                Entrar
            </Button>

            <p class="text-center text-sm text-muted-foreground">
                Ainda não tem conta?
                <TextLink :href="route('register')">Criar conta</TextLink>
            </p>
        </form>
    </AuthBase>
</template>
