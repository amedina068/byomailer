<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import BlockForm from '@/components/blocks/BlockForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Blocks',
        href: '/blocks',
    },
    {
        title: 'Create block',
        href: '/blocks/create',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create block" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Create block
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Define a reusable content block for your email
                            templates.
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link href="/blocks"> Back to blocks </Link>
                    </Button>
                </div>

                <div class="mt-6">
                    <BlockForm />
                </div>
            </div>
        </section>
    </AppLayout>
</template>
