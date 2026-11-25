<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import SegmentForm from '@/components/segments/SegmentForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    index as segmentsIndex,
    edit as segmentsEdit,
    show as segmentsShow,
} from '@/routes/segments';
import type { BreadcrumbItem } from '@/types';
import type { NewsletterFilter } from '@/types/newsletters';

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

interface SegmentData {
    id: number;
    name: string;
    description: string | null;
    filters: NewsletterFilter[];
}

interface UsedBy {
    newsletters: { id: number; title: string }[];
    sequences: { id: number; name: string }[];
}

const props = defineProps<{
    segment: SegmentData;
    usedBy: UsedBy;
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
        title: props.segment.name,
        href: segmentsShow(props.segment.id).url,
    },
    {
        title: 'Edit',
        href: segmentsEdit(props.segment.id).url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit ${segment.name}`" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Edit segment
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Changes apply live to every newsletter draft and
                            ongoing sequence that targets this segment.
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="segmentsShow(segment.id).url"
                            >Back to segment</Link
                        >
                    </Button>
                </div>

                <div class="mt-6">
                    <SegmentForm
                        :segment="segment"
                        :used-by="usedBy"
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
