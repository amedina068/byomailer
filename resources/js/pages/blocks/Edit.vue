<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import BlockForm from '@/components/blocks/BlockForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface Block {
    id: number;
    uuid: string;
    name: string;
    html_content: string;
    created_at: string;
    updated_at: string;
}

const props = defineProps<{
    block: Block;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Blocks',
        href: '/blocks',
    },
    {
        title: `Edit: ${props.block.name}`,
        href: `/blocks/${props.block.id}/edit`,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit block: ${block.name}`" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Edit block
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Update the content for
                            <code class="text-xs" v-text="'{{ block.' + block.name + ' }}'"></code>
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link href="/blocks"> Back to blocks </Link>
                    </Button>
                </div>

                <div class="mt-6">
                    <BlockForm :block="block" />
                </div>
            </div>
        </section>
    </AppLayout>
</template>
