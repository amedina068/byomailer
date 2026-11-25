<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { PhFunnel } from '@phosphor-icons/vue';
import { computed } from 'vue';

import SegmentRow from '@/components/segments/SegmentRow.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    archive as segmentsArchive,
    create as segmentsCreate,
    index as segmentsIndex,
    show as segmentsShow,
} from '@/routes/segments';
import type { BreadcrumbItem } from '@/types';
import type { NewsletterFilter } from '@/types/newsletters';

interface SegmentListItem {
    id: number;
    name: string;
    description: string | null;
    filters: NewsletterFilter[];
    archived_at?: string | null;
    updated_at?: string | null;
}

const props = defineProps<{
    segments: SegmentListItem[];
    counts?: Record<number, number>;
    showingArchived: boolean;
    activeCount: number;
    archivedCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Segments',
        href: segmentsIndex().url,
    },
];

const hasSegments = computed(() => props.segments.length > 0);

const countFor = (id: number): number | undefined => props.counts?.[id];

const setArchived = (segment: SegmentListItem, archived: boolean): void => {
    router.patch(
        segmentsArchive(segment.id).url,
        { archived },
        { preserveScroll: true },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Segments" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-foreground">
                            Segments
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Save a set of filters once, then use it in any
                            newsletter or sequence. The segment always uses the
                            contacts that match the filters now.
                        </p>
                    </div>
                    <Button as-child size="sm">
                        <Link :href="segmentsCreate().url">New segment</Link>
                    </Button>
                </div>
            </div>

            <div
                v-if="archivedCount > 0 || showingArchived"
                class="flex items-center gap-1 text-sm"
            >
                <Link
                    :href="segmentsIndex().url"
                    class="rounded-md px-3 py-1.5 font-medium transition-colors"
                    :style="
                        !showingArchived
                            ? 'background-color: var(--bm-surface-2); color: var(--bm-text-1);'
                            : 'color: var(--bm-text-3);'
                    "
                >
                    Active
                    <span
                        class="ml-1 tabular-nums"
                        style="font-family: var(--bm-font-mono)"
                        >{{ activeCount }}</span
                    >
                </Link>
                <Link
                    :href="segmentsIndex({ query: { archived: 1 } }).url"
                    class="rounded-md px-3 py-1.5 font-medium transition-colors"
                    :style="
                        showingArchived
                            ? 'background-color: var(--bm-surface-2); color: var(--bm-text-1);'
                            : 'color: var(--bm-text-3);'
                    "
                >
                    Archived
                    <span
                        class="ml-1 tabular-nums"
                        style="font-family: var(--bm-font-mono)"
                        >{{ archivedCount }}</span
                    >
                </Link>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card dark:border-sidebar-border"
            >
                <!-- Triggers the single deferred `counts` load; rows read it reactively. -->
                <Deferred data="counts">
                    <template #fallback></template>
                </Deferred>

                <div v-if="hasSegments" class="overflow-x-auto rounded-xl">
                    <table class="min-w-full text-sm">
                        <thead
                            class="text-left text-[10px] tracking-widest uppercase"
                            style="
                                background-color: var(--bm-surface-2);
                                color: var(--bm-text-3);
                            "
                        >
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">
                                    Name
                                </th>
                                <th scope="col" class="px-4 py-2.5 font-medium">
                                    Filters
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2.5 text-right font-medium"
                                >
                                    Contacts
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2.5 text-right font-medium"
                                >
                                    Updated
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <SegmentRow
                                v-for="segment in props.segments"
                                :key="segment.id"
                                :segment="segment"
                                :count="countFor(segment.id)"
                                :view-url="segmentsShow(segment.id).url"
                                @archive="setArchived(segment, true)"
                                @restore="setArchived(segment, false)"
                            />
                        </tbody>
                    </table>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-6 py-16 text-center"
                >
                    <PhFunnel :size="32" style="color: var(--bm-text-4)" />
                    <div class="space-y-1">
                        <p
                            class="text-sm font-medium"
                            style="color: var(--bm-text-1)"
                        >
                            {{
                                showingArchived
                                    ? 'No archived segments'
                                    : 'No segments yet'
                            }}
                        </p>
                        <p class="text-sm" style="color: var(--bm-text-3)">
                            {{
                                showingArchived
                                    ? 'Segments you archive will appear here. Restore one to use it again.'
                                    : 'Create a saved segment to reuse the same audience across newsletters and sequences.'
                            }}
                        </p>
                    </div>
                    <Button v-if="!showingArchived" as-child size="sm">
                        <Link :href="segmentsCreate().url">New segment</Link>
                    </Button>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
