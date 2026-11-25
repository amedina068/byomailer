<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhMagnifyingGlass, PhPlus } from '@phosphor-icons/vue';
import { computed } from 'vue';

import NewsletterRow from '@/components/newsletters/NewsletterRow.vue';
import ProjectFilterDropdown from '@/components/projects/ProjectFilterDropdown.vue';
import SesPermissionBanner from '@/components/SesPermissionBanner.vue';
import { Button } from '@/components/ui/button';
import { useProjectFilter } from '@/composables/useProjectFilter';
import { useUrlQueryParam } from '@/composables/useUrlQueryParam';
import AppLayout from '@/layouts/AppLayout.vue';
import { matchesProjectFilter } from '@/lib/filterByProject';
import {
    create as newsletterCreate,
    show as newsletterShow,
    index as newslettersIndex,
} from '@/routes/newsletters';
import { sync as newsletterProjectsSync } from '@/routes/newsletters/projects';
import type { BreadcrumbItem } from '@/types';
import type { NewsletterStatusOption } from '@/types/newsletters';

interface NewsletterList {
    id: number;
    name: string;
}

interface ProjectOption {
    id: number;
    name: string;
}

interface NewsletterMetrics {
    total: number;
    sent: number;
    pending: number;
    opened: number;
    clicked: number;
    bounced: number;
    unsubscribed: number;
    complained: number;
    open_rate: number | null;
    click_rate: number | null;
    unsubscribe_rate: number | null;
}

interface Newsletter {
    id: number;
    title?: string | null;
    subject: string;
    status: string;
    scheduled_at?: string | null;
    sent_at?: string | null;
    created_at?: string | null;
    list?: NewsletterList | null;
    ab_test_enabled?: boolean | null;
    project_ids?: number[];
    metrics: NewsletterMetrics;
}

const props = defineProps<{
    newsletters: Newsletter[];
    newsletterStatuses: NewsletterStatusOption[];
    projects: ProjectOption[];
    missingPermissions: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Newsletters',
        href: newslettersIndex().url,
    },
];

const statusTabs = computed((): NewsletterStatusOption[] => [
    { value: 'all', label: 'All' },
    ...props.newsletterStatuses,
]);

const statusFilter = useUrlQueryParam<string>('status', 'all', {
    validate: (raw) => (statusTabs.value.some((tab) => tab.value === raw) ? raw : null),
});

const searchQuery = useUrlQueryParam('search', '', { debounceMs: 200 });

const projectFilter = useUrlQueryParam<string>('project', 'all');

const { validProjectIds } = useProjectFilter(() => props.projects, projectFilter);

const statusCounts = computed(() => {
    const counts: Record<string, number> = { all: props.newsletters.length };
    for (const n of props.newsletters) {
        counts[n.status] = (counts[n.status] ?? 0) + 1;
    }
    return counts;
});

const filteredNewsletters = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.newsletters
        .filter((n) => {
            if (statusFilter.value !== 'all' && n.status !== statusFilter.value) {
                return false;
            }
            if (!matchesProjectFilter(n.project_ids, projectFilter.value, validProjectIds.value)) {
                return false;
            }
            if (!q) {
                return true;
            }
            const haystack = [n.title ?? '', n.subject ?? '', n.list?.name ?? '']
                .join(' ')
                .toLowerCase();
            return haystack.includes(q);
        })
        .slice()
        .sort((a, b) => b.id - a.id);
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Newsletters" />

        <section class="space-y-6">
            <SesPermissionBanner
                :missing-permissions="missingPermissions"
                message="Your AWS credentials are missing permissions required to send newsletters. Scheduling and sending will be blocked."
            />

            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1
                        class="text-2xl font-semibold"
                        style="color: var(--bm-text-1);"
                    >
                        Newsletters
                    </h1>
                    <p class="mt-1 text-sm" style="color: var(--bm-text-3);">
                        Broadcast campaigns sent through your own SES. Search, filter, and track every send.
                    </p>
                </div>
                <Button as-child size="sm">
                    <Link :href="newsletterCreate().url" class="inline-flex items-center gap-1.5">
                        <PhPlus :size="14" weight="bold" />
                        New newsletter
                    </Link>
                </Button>
            </div>

            <!-- Toolbar -->
            <div
                class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between"
                style="background-color: var(--bm-surface); border: 1px solid var(--bm-border); border-radius: var(--bm-radius-md);"
            >
                <div class="flex flex-wrap items-center gap-1">
                    <button
                        v-for="tab in statusTabs"
                        :key="tab.value"
                        type="button"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium transition"
                        :aria-pressed="statusFilter === tab.value"
                        :style="{
                            backgroundColor:
                                statusFilter === tab.value
                                    ? 'var(--bm-accent-bright-dim)'
                                    : 'transparent',
                            color:
                                statusFilter === tab.value
                                    ? 'var(--bm-accent)'
                                    : 'var(--bm-text-3)',
                            borderRadius: 'var(--bm-radius)',
                        }"
                        @click="statusFilter = tab.value"
                    >
                        {{ tab.label }}
                        <span
                            class="tabular-nums"
                            style="font-family: var(--bm-font-mono); font-size: 10px; color: var(--bm-text-4);"
                        >
                            {{ statusCounts[tab.value] ?? 0 }}
                        </span>
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <ProjectFilterDropdown
                        v-model="projectFilter"
                        :projects="props.projects"
                        align="start"
                    />

                    <div
                        class="relative flex items-center sm:w-72"
                        style="background-color: var(--bm-base); border: 1px solid var(--bm-border); border-radius: var(--bm-radius);"
                    >
                        <PhMagnifyingGlass
                            :size="14"
                            class="ml-2.5 shrink-0"
                            :style="{ color: 'var(--bm-text-4)' }"
                        />
                        <input
                            v-model="searchQuery"
                            type="search"
                            placeholder="Search subject, title, list…"
                            aria-label="Search newsletters"
                            class="w-full bg-transparent px-2.5 py-1.5 text-sm focus-visible:outline-none"
                            style="color: var(--bm-text-1);"
                        />
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div
                style="background-color: var(--bm-surface); border: 1px solid var(--bm-border); border-radius: var(--bm-radius-md); box-shadow: var(--bm-shadow-xs); overflow-x: auto;"
            >
                <div
                    v-if="filteredNewsletters.length === 0 && props.newsletters.length === 0"
                    class="p-6"
                >
                    <div
                        class="flex flex-col items-center gap-4 px-6 py-10 text-center"
                        style="border: 1px dashed var(--bm-border); border-radius: var(--bm-radius-md);"
                    >
                        <p class="text-sm" style="color: var(--bm-text-3);">
                            No newsletters yet. Draft one to start sending.
                        </p>
                        <Button as-child size="sm">
                            <Link :href="newsletterCreate().url" class="inline-flex items-center gap-1.5">
                                <PhPlus :size="14" weight="bold" />
                                Create newsletter
                            </Link>
                        </Button>
                    </div>
                </div>

                <div
                    v-else-if="filteredNewsletters.length === 0"
                    class="px-6 py-10 text-center text-sm"
                    style="color: var(--bm-text-3);"
                >
                    No newsletters match these filters.
                </div>

                <table v-else class="min-w-full text-sm">
                    <thead>
                        <tr
                            style="
                                background-color: var(--bm-surface-2);
                                border-bottom: 1px solid var(--bm-border-strong);
                            "
                        >
                            <th
                                class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Newsletter
                            </th>
                            <th
                                class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Status
                            </th>
                            <th
                                class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Recipients
                            </th>
                            <th
                                class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Sent
                            </th>
                            <th
                                class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Open
                            </th>
                            <th
                                class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Click
                            </th>
                            <th
                                class="px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-widest"
                                style="color: var(--bm-text-3);"
                            >
                                Unsub
                            </th>
                            <th v-if="props.projects.length > 0" class="px-2 py-2.5">
                                <span class="sr-only">Projects</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <NewsletterRow
                            v-for="newsletter in filteredNewsletters"
                            :key="newsletter.id"
                            :newsletter="newsletter"
                            :statuses="props.newsletterStatuses"
                            :view-url="newsletterShow(newsletter.id).url"
                            :project-ids="newsletter.project_ids ?? []"
                            :projects="props.projects"
                            :sync-url="newsletterProjectsSync(newsletter.id).url"
                        />
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
