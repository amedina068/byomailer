<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PhFlowArrow, PhMagnifyingGlass, PhPlus } from '@phosphor-icons/vue';
import { computed } from 'vue';

import AutomationRow from '@/components/automations/AutomationRow.vue';
import ProjectFilterDropdown from '@/components/projects/ProjectFilterDropdown.vue';
import { Button } from '@/components/ui/button';
import { useProjectFilter } from '@/composables/useProjectFilter';
import { useUrlQueryParam } from '@/composables/useUrlQueryParam';
import AppLayout from '@/layouts/AppLayout.vue';
import { matchesProjectFilter } from '@/lib/filterByProject';
import {
    create as createRoute,
    edit as editRoute,
    index as indexRoute,
} from '@/routes/automations';
import { sync as automationProjectsSync } from '@/routes/automations/projects';
import type { BreadcrumbItem } from '@/types';

type ConditionType =
    | 'contact_added_to_list'
    | 'removed_from_list'
    | 'tag_added'
    | 'removed_tag'
    | 'added_to_sequence'
    | 'sequence_finished'
    | 'attribute_changed';

interface Automation {
    id: number;
    name: string;
    type: string;
    condition: {
        type: ConditionType;
        value?: string | number | null;
    };
    actions: Array<{
        type:
            | 'add_tag'
            | 'remove_tag'
            | 'add_list'
            | 'remove_list'
            | 'add_sequence';
        value?: string | number | null;
    }>;
    is_live: boolean;
    created_at: string;
    project_ids?: number[];
}

interface CatalogItem {
    id: number;
    name: string;
}

interface ProjectOption {
    id: number;
    name: string;
}

const props = defineProps<{
    automations: Automation[];
    projects: ProjectOption[];
    catalogs: {
        lists: CatalogItem[];
        tags: CatalogItem[];
        sequences: CatalogItem[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Automations', href: indexRoute().url },
];

const listNames = computed(() =>
    props.catalogs.lists.reduce<Record<string, string>>((carry, list) => {
        carry[String(list.id)] = list.name;
        return carry;
    }, {}),
);

const tagNames = computed(() =>
    props.catalogs.tags.reduce<Record<string, string>>((carry, tag) => {
        carry[String(tag.id)] = tag.name;
        return carry;
    }, {}),
);

const sequenceNames = computed(() =>
    props.catalogs.sequences.reduce<Record<string, string>>(
        (carry, sequence) => {
            carry[String(sequence.id)] = sequence.name;
            return carry;
        },
        {},
    ),
);

type StatusFilter = 'all' | 'live' | 'paused';

const VALID_STATUSES: readonly StatusFilter[] = ['all', 'live', 'paused'];

const statusFilter = useUrlQueryParam<StatusFilter>('status', 'all', {
    validate: (raw) =>
        (VALID_STATUSES as readonly string[]).includes(raw)
            ? (raw as StatusFilter)
            : null,
});

const searchQuery = useUrlQueryParam('search', '', { debounceMs: 200 });

const projectFilter = useUrlQueryParam<string>('project', 'all');

const { validProjectIds } = useProjectFilter(() => props.projects, projectFilter);

const statusCounts = computed(() => {
    const counts: Record<StatusFilter, number> = {
        all: props.automations.length,
        live: 0,
        paused: 0,
    };
    for (const automation of props.automations) {
        if (automation.is_live) {
            counts.live += 1;
        } else {
            counts.paused += 1;
        }
    }
    return counts;
});

const statusTabs: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'live', label: 'Live' },
    { value: 'paused', label: 'Paused' },
];

const filteredAutomations = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    return props.automations.filter((automation) => {
        if (statusFilter.value === 'live' && !automation.is_live) return false;
        if (statusFilter.value === 'paused' && automation.is_live) return false;
        if (!matchesProjectFilter(automation.project_ids, projectFilter.value, validProjectIds.value)) {
            return false;
        }
        if (!query) return true;
        return automation.name.toLowerCase().includes(query);
    });
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Automations" />

        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1
                        class="text-2xl font-semibold"
                        style="color: var(--bm-text-1);"
                    >
                        Automations
                    </h1>
                    <p class="mt-1 text-sm" style="color: var(--bm-text-3);">
                        Rules that fire when contacts hit a trigger — tag, list, sequence, or attribute change.
                    </p>
                </div>
                <Button as-child size="sm">
                    <Link :href="createRoute().url" class="inline-flex items-center gap-1.5">
                        <PhPlus :size="14" weight="bold" />
                        New automation
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
                            placeholder="Search automations…"
                            class="w-full bg-transparent px-2.5 py-1.5 text-sm focus-visible:outline-none"
                            style="color: var(--bm-text-1);"
                        />
                    </div>
                </div>
            </div>

            <!-- List -->
            <div
                style="background-color: var(--bm-surface); border: 1px solid var(--bm-border); border-radius: var(--bm-radius-md); box-shadow: var(--bm-shadow-xs); overflow: hidden;"
            >
                <div
                    v-if="props.automations.length === 0"
                    class="p-6"
                >
                    <div
                        class="flex flex-col items-center gap-4 px-6 py-10 text-center"
                        style="border: 1px dashed var(--bm-border); border-radius: var(--bm-radius-md);"
                    >
                        <PhFlowArrow :size="28" :style="{ color: 'var(--bm-text-4)' }" />
                        <p class="text-sm" style="color: var(--bm-text-3);">
                            No automations yet. Create a rule to react when contacts get tagged, join a list, or finish a sequence.
                        </p>
                        <Button as-child size="sm">
                            <Link :href="createRoute().url" class="inline-flex items-center gap-1.5">
                                <PhPlus :size="14" weight="bold" />
                                New automation
                            </Link>
                        </Button>
                    </div>
                </div>

                <div
                    v-else-if="filteredAutomations.length === 0"
                    class="px-6 py-10 text-center text-sm"
                    style="color: var(--bm-text-3);"
                >
                    No automations match these filters.
                </div>

                <template v-else>
                    <AutomationRow
                        v-for="automation in filteredAutomations"
                        :key="automation.id"
                        :automation="automation"
                        :edit-url="editRoute(automation.id).url"
                        :list-names="listNames"
                        :tag-names="tagNames"
                        :sequence-names="sequenceNames"
                        :project-ids="automation.project_ids ?? []"
                        :projects="props.projects"
                        :sync-url="automationProjectsSync(automation.id).url"
                    />
                </template>
            </div>
        </section>
    </AppLayout>
</template>
