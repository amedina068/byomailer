<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
    maxWidth?: string;
}>();
</script>

<template>
    <div
        data-bm
        class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10"
        style="font-family: var(--bm-font-sans); background-color: var(--bm-base); color: var(--bm-text-1); -webkit-font-smoothing: antialiased;"
    >
        <div class="w-full" :style="{ maxWidth: maxWidth ?? '24rem' }">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link :href="home()" class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center" style="background-color: var(--bm-accent); border-radius: var(--bm-radius-sm);">
                            <span class="text-sm font-bold" style="font-family: var(--bm-font-mono); color: var(--bm-accent-text);">B</span>
                        </div>
                        <span class="text-base font-bold" style="color: var(--bm-text-1);">BYOMailer</span>
                    </Link>
                    <div class="mt-2 space-y-2 text-center">
                        <h1 class="text-xl font-semibold" style="color: var(--bm-text-1);">{{ title }}</h1>
                        <p class="text-center text-sm" style="color: var(--bm-text-3);">{{ description }}</p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
