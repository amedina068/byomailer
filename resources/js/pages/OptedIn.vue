<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    contact: {
        name: string | null;
        email: string;
    };
    list: {
        name: string;
    };
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6 md:p-10"
    >
        <Head title="Subscription confirmed" />

        <div class="max-w-md text-center">
            <h1
                class="text-2xl font-semibold tracking-tight text-balance text-foreground"
            >
                Subscription confirmed
            </h1>
            <p class="mt-2 text-pretty text-muted-foreground">
                {{ contact.name ?? contact.email }}, you are subscribed to
                {{ list.name }}.
            </p>
        </div>
    </div>
</template>
