<script setup lang="ts">
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <div v-if="flash.success || flash.warning || flash.error" class="space-y-2" role="status" aria-live="polite">
        <p
            v-if="flash.success"
            class="flex items-start gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200"
        >
            <CheckCircle2 class="mt-0.5 size-4 shrink-0" /> {{ flash.success }}
        </p>
        <p
            v-if="flash.warning"
            class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" /> {{ flash.warning }}
        </p>
        <p
            v-if="flash.error"
            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200"
        >
            <XCircle class="mt-0.5 size-4 shrink-0" /> {{ flash.error }}
        </p>
    </div>
</template>
