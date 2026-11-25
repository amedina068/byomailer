<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import SegmentForm from '@/components/segments/SegmentForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { create as segmentsCreate, index as segmentsIndex } from '@/routes/segments';
import type { BreadcrumbItem } from '@/types';

interface MailList {
    id: number;
    name: string;
}

interface TagOption {
    id: number;
    name: string;
}

interface TopicOption {
    id: number;
    name: string;
    list_id: number;
    list_name: string | null;
}

defineProps<{
    lists: MailList[];
    tags: TagOption[];
    topics?: TopicOption[];
    attributeKeys: string[];
    filterNewsletters?: { id: number; title: string }[];
    filterCountries?: string[];
    filterTimezones?: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Segments',
        href: segmentsIndex().url,
    },
    {
        title: 'New segment',
        href: segmentsCreate().url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="New segment" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            New segment
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Define a reusable audience with the same filters you
                            use on newsletters and sequences.
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="segmentsIndex().url">Back to segments</Link>
                    </Button>
                </div>

                <div class="mt-6">
                    <SegmentForm
                        :lists="lists"
                        :tags="tags"
                        :topics="topics ?? []"
                        :attribute-keys="attributeKeys"
                        :filter-newsletters="filterNewsletters ?? []"
                        :filter-countries="filterCountries ?? []"
                        :filter-timezones="filterTimezones ?? []"
                    />
                </div>
            </div>
        </section>
    </AppLayout>
</template>
