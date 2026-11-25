<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhMagnifyingGlass, PhPlus } from '@phosphor-icons/vue';
import { computed } from 'vue';

import ProjectFilterDropdown from '@/components/projects/ProjectFilterDropdown.vue';
import SequenceCard from '@/components/sequences/SequenceCard.vue';
import SesPermissionBanner from '@/components/SesPermissionBanner.vue';
import { Button } from '@/components/ui/button';
import { useProjectFilter } from '@/composables/useProjectFilter';
import { useUrlQueryParam } from '@/composables/useUrlQueryParam';
import AppLayout from '@/layouts/AppLayout.vue';
import { matchesProjectFilter } from '@/lib/filterByProject';
import {
    create as sequenceCreate,
    show as sequenceShow,
    index as sequencesIndex,
} from '@/routes/sequences';
import { sync as sequenceProjectsSync } from '@/routes/sequences/projects';
import type { BreadcrumbItem } from '@/types';
import type { SequenceStatusOption } from '@/types/sequences';

interface ProjectOption {
    id: number;
    name: string;
}

interface Sequence {
    id: number;
    name: string;
    status: string;
    steps_count: number;
    contacts_count?: number;
    active_count?: number;
    completed_count?: number;
    created_at: string;
    project_ids?: number[];
}

const props = defineProps<{
    sequences: Sequence[];
    sequenceStatuses: SequenceStatusOption[];
    projects: ProjectOption[];
    missingPermissions: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Sequences',
        href: sequencesIndex().url,
    },
];

const statusTabs = computed((): SequenceStatusOption[] => [
    { value: 'all', label: 'All' },
    ...props.sequenceStatuses,
]);

const statusFilter = useUrlQueryParam<string>('status', 'all', {
    validate: (raw) => (statusTabs.value.some((tab) => tab.value === raw) ? raw : null),
});

const searchQuery = useUrlQueryParam('search', '', { debounceMs: 200 });

const projectFilter = useUrlQueryParam<string>('project', 'all');

const { validProjectIds } = useProjectFilter(() => props.projects, projectFilter);

const statusCounts = computed(() => {
    const counts: Record<string, number> = { all: props.sequences.length };
    for (const sequence of props.sequences) {
        counts[sequence.status] = (counts[sequence.status] ?? 0) + 1;
    }
    return counts;
});

const filteredSequences = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    return props.sequences
        .filter((sequence) => {
            if (statusFilter.value !== 'all' && sequence.status !== statusFilter.value) {
                return false;
            }
            if (!matchesProjectFilter(sequence.project_ids, projectFilter.value, validProjectIds.value)) {
                return false;
            }
            if (!query) {
                return true;
            }
            return sequence.name.toLowerCase().includes(query);
        })
        .slice()
        .sort((a, b) => b.id - a.id);
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Sequences" />

        <section class="space-y-6">
            <SesPermissionBanner
                :missing-permissions="missingPermissions"
                message="Your AWS credentials are missing the ses:SendEmail permission. Sequence emails will not be sent."
            />

            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1
                        class="text-2xl font-semibold"
                        style="color: var(--bm-text-1);"
                    >
                        Sequences
                    </h1>
                    <p class="mt-1 text-sm" style="color: var(--bm-text-3);">
                        Drip campaigns that automate follow-ups through your own SES.
                    </p>
                </div>
                <Button as-child size="sm">
                    <Link :href="sequenceCreate().url" class="inline-flex items-center gap-1.5">
                        <PhPlus :size="14" weight="bold" />
                        Create sequence
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
                            placeholder="Search sequences…"
                            class="w-full bg-transparent px-2.5 py-1.5 text-sm focus-visible:outline-none"
                            style="color: var(--bm-text-1);"
                        />
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="props.sequences.length === 0"
                class="flex flex-col items-center gap-4 px-6 py-12 text-center"
                style="border: 1px dashed var(--bm-border); border-radius: var(--bm-radius-md);"
            >
                <p class="text-sm" style="color: var(--bm-text-3);">
                    No sequences yet. Build an onboarding journey to start automating follow-ups.
                </p>
                <Button as-child size="sm">
                    <Link :href="sequenceCreate().url" class="inline-flex items-center gap-1.5">
                        <PhPlus :size="14" weight="bold" />
                        Create sequence
                    </Link>
                </Button>
            </div>

            <!-- No matches -->
            <div
                v-else-if="filteredSequences.length === 0"
                class="px-6 py-12 text-center text-sm"
                style="border: 1px dashed var(--bm-border); border-radius: var(--bm-radius-md); color: var(--bm-text-3);"
            >
                No sequences match these filters.
            </div>

            <!-- Card list -->
            <div v-else role="list" class="flex flex-col gap-3">
                <SequenceCard
                    v-for="sequence in filteredSequences"
                    :key="sequence.id"
                    :sequence="sequence"
                    :statuses="props.sequenceStatuses"
                    :view-url="sequenceShow(sequence.id).url"
                    :project-ids="sequence.project_ids ?? []"
                    :projects="props.projects"
                    :sync-url="sequenceProjectsSync(sequence.id).url"
                />
            </div>
        </section>
    </AppLayout>
</template>
