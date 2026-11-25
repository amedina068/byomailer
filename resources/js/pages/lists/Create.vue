<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import ListForm from '@/components/lists/ListForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { create as listsCreate, index as listsIndex } from '@/routes/lists';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Lists',
        href: listsIndex().url,
    },
    {
        title: 'Create list',
        href: listsCreate().url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create list" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Create list
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Name your list before you import contacts or start
                            automations.
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="listsIndex().url"> Back to lists </Link>
                    </Button>
                </div>

                <div class="mt-6">
                    <ListForm />
                </div>
            </div>
        </section>
    </AppLayout>
</template>
