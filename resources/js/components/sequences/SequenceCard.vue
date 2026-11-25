<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhCaretRight, PhCircleDashed, PhPulse } from '@phosphor-icons/vue';
import { computed } from 'vue';

import ProjectBadge from '@/components/projects/ProjectBadge.vue';
import ProjectQuickAssign from '@/components/projects/ProjectQuickAssign.vue';
import { sequenceStatusLabel, type SequenceStatusOption } from '@/types/sequences';

interface ProjectOption {
    id: number;
    name: string;
}

interface SequenceCardData {
    id: number;
    name: string;
    status: string;
    steps_count?: number;
    active_count?: number;
    completed_count?: number;
    created_at?: string | null;
}

const props = defineProps<{
    sequence: SequenceCardData;
    statuses: SequenceStatusOption[];
    viewUrl: string;
    projectIds?: number[];
    projects?: ProjectOption[];
    syncUrl?: string;
}>();

const assignedProjects = computed((): ProjectOption[] => {
    const ids = props.projectIds ?? [];
    return (props.projects ?? []).filter((project) => ids.includes(project.id));
});

const visibleProjects = computed(() => assignedProjects.value.slice(0, 2));

const overflowProjectCount = computed(() =>
    Math.max(assignedProjects.value.length - visibleProjects.value.length, 0),
);

const formatNumber = (value: number | null | undefined): string =>
    value === null || value === undefined ? '0' : value.toLocaleString();

const formatCreated = (value?: string | null): string => {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }
    const diffMin = Math.round((Date.now() - date.getTime()) / 60_000);
    if (diffMin < 1) {
        return 'just now';
    }
    if (diffMin < 60) {
        return `${diffMin}m ago`;
    }
    const hours = Math.round(diffMin / 60);
    if (hours < 24) {
        return `${hours}h ago`;
    }
    const days = Math.round(hours / 24);
    if (days < 30) {
        return `${days}d ago`;
    }
    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

const statusMeta = computed(() => ({
    label: sequenceStatusLabel(props.statuses, props.sequence.status),
    ...(props.sequence.status === 'live'
        ? { icon: PhPulse, fg: 'var(--bm-success)', bg: 'var(--bm-success-dim)' }
        : { icon: PhCircleDashed, fg: 'var(--bm-text-3)', bg: 'var(--bm-surface-2)' }),
}));

const stats = computed(() => [
    { label: 'Steps', value: props.sequence.steps_count },
    { label: 'Active', value: props.sequence.active_count },
    { label: 'Completed', value: props.sequence.completed_count },
]);
</script>

<template>
    <div
        class="group relative flex flex-col gap-4 p-4 transition-colors sm:flex-row sm:items-center sm:justify-between sm:gap-6 hover:bg-[var(--bm-surface-2)]"
        style="background-color: var(--bm-surface); border: 1px solid var(--bm-border); border-radius: var(--bm-radius-md); box-shadow: var(--bm-shadow-xs);"
    >
        <Link
            prefetch
            :href="viewUrl"
            class="absolute inset-0 focus-visible:outline-none"
            :aria-label="sequence.name"
            :style="{ borderRadius: 'var(--bm-radius-md)' }"
        />
        <div class="flex min-w-0 flex-1 items-start gap-3">
            <span
                class="mt-0.5 flex size-8 shrink-0 items-center justify-center"
                :style="{
                    backgroundColor: statusMeta.bg,
                    color: statusMeta.fg,
                    borderRadius: 'var(--bm-radius)',
                }"
            >
                <component :is="statusMeta.icon" :size="16" />
            </span>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h3
                        class="truncate text-sm font-medium"
                        style="color: var(--bm-text-1);"
                    >
                        {{ sequence.name }}
                    </h3>
                    <span
                        class="inline-flex shrink-0 items-center gap-1 px-1.5 py-0.5"
                        :style="{
                            backgroundColor: statusMeta.bg,
                            color: statusMeta.fg,
                            fontFamily: 'var(--bm-font-mono)',
                            fontSize: '10px',
                            fontWeight: 500,
                            borderRadius: '3px',
                        }"
                    >
                        {{ statusMeta.label }}
                    </span>
                </div>
                <p class="mt-0.5 text-xs" style="color: var(--bm-text-3);">
                    Created {{ formatCreated(sequence.created_at) }}
                </p>
                <div
                    v-if="visibleProjects.length > 0"
                    class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5"
                >
                    <ProjectBadge
                        v-for="project in visibleProjects"
                        :key="project.id"
                        :name="project.name"
                    />
                    <span
                        v-if="overflowProjectCount > 0"
                        class="tabular-nums"
                        style="font-family: var(--bm-font-mono); font-size: 10px; color: var(--bm-text-4);"
                        :title="assignedProjects.slice(2).map((p) => p.name).join(', ')"
                    >
                        +{{ overflowProjectCount }}
                    </span>
                </div>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-1 sm:gap-0">
            <div
                v-for="stat in stats"
                :key="stat.label"
                class="flex-1 px-4 text-center sm:flex-none sm:text-right [&:not(:first-child)]:border-l"
                style="border-color: var(--bm-border);"
            >
                <p
                    class="text-base font-semibold tabular-nums"
                    style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
                >
                    {{ formatNumber(stat.value) }}
                </p>
                <p
                    class="mt-0.5 text-[10px] font-semibold uppercase tracking-widest"
                    style="color: var(--bm-text-3);"
                >
                    {{ stat.label }}
                </p>
            </div>
            <div
                v-if="projects && projects.length > 0 && syncUrl"
                class="relative ml-1 shrink-0"
            >
                <ProjectQuickAssign
                    :project-ids="projectIds ?? []"
                    :options="projects"
                    :sync-url="syncUrl"
                    :reload-props="['sequences']"
                />
            </div>
            <PhCaretRight
                :size="16"
                class="ml-1 hidden shrink-0 sm:block"
                style="color: var(--bm-text-3);"
            />
        </div>
    </div>
</template>
