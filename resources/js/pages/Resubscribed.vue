<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    isTestMail?: boolean;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center bg-background p-6 md:p-10">
        <Head title="Subscribed Again" />

        <div class="max-w-md text-center">
            <div
                v-if="isTestMail"
                class="mb-6 rounded-[var(--bm-radius-md)] border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                <span class="font-semibold">Test email preview</span> —
                no action was taken. Your contacts are unchanged.
            </div>

            <h1 class="text-2xl font-semibold text-foreground">You have been subscribed again</h1>
            <p class="mt-2 text-muted-foreground">You have successfully resubscribed. You will continue receiving emails.</p>
        </div>
    </div>
</template>
