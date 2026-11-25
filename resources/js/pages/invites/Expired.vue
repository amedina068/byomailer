<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { home } from '@/routes';

defineProps<{
    message: string;
}>();
</script>

<template>
    <AuthLayout
        title="Invitation expired"
        description="This link is no longer valid."
    >
        <Head title="Invitation expired" />

        <div class="mx-auto flex w-full max-w-sm flex-col gap-4 rounded-xl border border-border bg-card p-6 text-center shadow-sm">
            <p class="text-sm text-muted-foreground">
                {{ message }}
            </p>
            <Button as-child variant="ghost">
                <a :href="home().url">Return home</a>
            </Button>
        </div>
    </AuthLayout>
</template>
