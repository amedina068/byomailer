<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    PhArrowRight,
    PhCaretRight,
    PhPause,
    PhPlugsConnected,
    PhPulse,
} from '@phosphor-icons/vue';
import { computed } from 'vue';

import ProjectBadge from '@/components/projects/ProjectBadge.vue';
import ProjectQuickAssign from '@/components/projects/ProjectQuickAssign.vue';

type ConditionType =
    | 'contact_added_to_list'
    | 'removed_from_list'
    | 'tag_added'
    | 'removed_tag'
    | 'added_to_sequence'
    | 'sequence_finished'
    | 'attribute_changed';

type ActionType =
    | 'add_tag'
    | 'remove_tag'
    | 'add_list'
    | 'remove_list'
    | 'add_sequence';

interface Condition {
    type: ConditionType;
    attribute?: string | null;
    operator?: 'changed_to' | 'changed' | null;
    value?: string | number | null;
}

interface Action {
    type: ActionType;
    value?: string | number | null;
}

interface NameLookup {
    [key: string]: string;
}

interface ProjectOption {
    id: number;
    name: string;
}

interface AutomationRowData {
    id: number;
    name: string;
    type: string;
    condition: Condition;
    actions: Action[];
    is_live: boolean;
    created_at?: string | null;
}

const props = defineProps<{
    automation: AutomationRowData;
    editUrl: string;
    listNames: NameLookup;
    tagNames: NameLookup;
    sequenceNames: NameLookup;
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

const resolveListName = (value?: string | number | null): string => {
    if (!value) return 'list';
    return props.listNames[String(value)] ?? `list #${value}`;
};

const resolveTagName = (value?: string | number | null): string => {
    if (!value) return 'tag';
    return props.tagNames[String(value)] ?? `tag #${value}`;
};

const resolveSequenceName = (value?: string | number | null): string => {
    if (!value) return 'sequence';
    return props.sequenceNames[String(value)] ?? `sequence #${value}`;
};

interface ChipDescriptor {
    label: string;
    target: string;
    kind: 'list' | 'tag' | 'sequence';
}

const triggerChip = computed<ChipDescriptor>(() => {
    const { type, value, attribute, operator } = props.automation.condition;
    switch (type) {
        case 'contact_added_to_list':
            return { label: 'Added to', target: resolveListName(value), kind: 'list' };
        case 'removed_from_list':
            return { label: 'Removed from', target: resolveListName(value), kind: 'list' };
        case 'tag_added':
            return { label: 'Tagged', target: resolveTagName(value), kind: 'tag' };
        case 'removed_tag':
            return { label: 'Untagged', target: resolveTagName(value), kind: 'tag' };
        case 'added_to_sequence':
            return { label: 'Joined sequence', target: resolveSequenceName(value), kind: 'sequence' };
        case 'sequence_finished':
            return { label: 'Finished sequence', target: resolveSequenceName(value), kind: 'sequence' };
        case 'attribute_changed':
            return operator === 'changed_to'
                ? { label: `${attribute} changes to`, target: `"${value ?? ''}"`, kind: 'tag' }
                : { label: `${attribute} changes`, target: '', kind: 'tag' };
        default:
            return { label: 'When', target: 'condition met', kind: 'tag' };
    }
});

const actionChips = computed<ChipDescriptor[]>(() =>
    props.automation.actions.map((action): ChipDescriptor => {
        switch (action.type) {
            case 'add_tag':
                return { label: 'Add tag', target: resolveTagName(action.value), kind: 'tag' };
            case 'remove_tag':
                return { label: 'Remove tag', target: resolveTagName(action.value), kind: 'tag' };
            case 'add_list':
                return { label: 'Add to', target: resolveListName(action.value), kind: 'list' };
            case 'remove_list':
                return { label: 'Remove from', target: resolveListName(action.value), kind: 'list' };
            case 'add_sequence':
                return { label: 'Enroll in', target: resolveSequenceName(action.value), kind: 'sequence' };
            default:
                return { label: 'Action', target: '—', kind: 'tag' };
        }
    }),
);

const chipColor = (kind: ChipDescriptor['kind']): { bg: string; fg: string } => {
    switch (kind) {
        case 'list':
            return { bg: 'var(--bm-info-light)', fg: 'var(--bm-info)' };
        case 'sequence':
            return { bg: 'var(--bm-accent-bright-dim)', fg: 'var(--bm-accent)' };
        case 'tag':
        default:
            return { bg: 'var(--bm-warning-light)', fg: 'var(--bm-warning)' };
    }
};

const formatCreated = (value?: string | null): string => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    const diffMin = Math.round((Date.now() - date.getTime()) / 60_000);
    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    const hours = Math.round(diffMin / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.round(hours / 24);
    if (days < 30) return `${days}d ago`;
    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

const navigate = (): void => {
    router.visit(props.editUrl);
};

const navigateFromKeyboard = (event: KeyboardEvent): void => {
    if (event.target !== event.currentTarget) return;
    event.preventDefault();
    navigate();
};

const onHover = (event: Event, on: boolean): void => {
    const el = event.currentTarget as HTMLElement;
    el.style.backgroundColor = on
        ? 'var(--bm-elevated)'
        : props.automation.is_live
            ? 'var(--bm-surface)'
            : 'var(--bm-surface-2)';
};
</script>

<template>
    <div
        class="group relative flex cursor-pointer items-stretch gap-4 px-4 py-4 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
        tabindex="0"
        role="button"
        :style="{
            borderBottom: '1px solid var(--bm-border)',
            backgroundColor: automation.is_live ? 'var(--bm-surface)' : 'var(--bm-surface-2)',
        }"
        @click="navigate"
        @keydown.enter="navigateFromKeyboard"
        @keydown.space="navigateFromKeyboard"
        @mouseenter="(e) => onHover(e, true)"
        @mouseleave="(e) => onHover(e, false)"
    >
        <!-- Status accent bar -->
        <span
            aria-hidden="true"
            class="absolute left-0 top-0 h-full w-[3px]"
            :style="{
                backgroundColor: automation.is_live ? 'var(--bm-success)' : 'var(--bm-border-strong)',
            }"
        />

        <!-- Main content -->
        <div class="flex min-w-0 flex-1 flex-col gap-2 pl-2">
            <div class="flex flex-wrap items-center gap-2">
                <h3
                    class="truncate text-[14px] font-semibold"
                    :style="{ color: automation.is_live ? 'var(--bm-text-1)' : 'var(--bm-text-2)' }"
                >
                    {{ automation.name }}
                </h3>

                <!-- Status badge -->
                <span
                    class="inline-flex items-center gap-1.5 px-2 py-0.5"
                    :style="{
                        backgroundColor: automation.is_live
                            ? 'var(--bm-success-light)'
                            : 'var(--bm-base)',
                        color: automation.is_live ? 'var(--bm-success)' : 'var(--bm-text-3)',
                        fontFamily: 'var(--bm-font-mono)',
                        fontSize: '10px',
                        fontWeight: 500,
                        borderRadius: '3px',
                        letterSpacing: '0.5px',
                        textTransform: 'uppercase',
                    }"
                >
                    <component :is="automation.is_live ? PhPulse : PhPause" :size="11" weight="bold" />
                    {{ automation.is_live ? 'Live' : 'Paused' }}
                </span>
            </div>

            <!-- WHEN → THEN chip flow -->
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="inline-flex items-center gap-1"
                    :style="{
                        fontFamily: 'var(--bm-font-mono)',
                        fontSize: '10px',
                        color: 'var(--bm-text-4)',
                        letterSpacing: '1px',
                        textTransform: 'uppercase',
                    }"
                >
                    <PhPlugsConnected :size="11" />
                    When
                </span>
                <span
                    class="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs"
                    :style="{
                        backgroundColor: chipColor(triggerChip.kind).bg,
                        color: chipColor(triggerChip.kind).fg,
                        borderRadius: '3px',
                        fontWeight: 500,
                    }"
                >
                    <span :style="{ opacity: 0.7 }">{{ triggerChip.label }}</span>
                    <span v-if="triggerChip.target" :style="{ fontFamily: 'var(--bm-font-mono)' }">{{ triggerChip.target }}</span>
                </span>

                <PhArrowRight :size="12" :style="{ color: 'var(--bm-text-4)' }" />

                <span
                    class="inline-flex items-center gap-1"
                    :style="{
                        fontFamily: 'var(--bm-font-mono)',
                        fontSize: '10px',
                        color: 'var(--bm-text-4)',
                        letterSpacing: '1px',
                        textTransform: 'uppercase',
                    }"
                >
                    Then
                </span>
                <template v-for="(chip, idx) in actionChips" :key="idx">
                    <span
                        class="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs"
                        :style="{
                            backgroundColor: chipColor(chip.kind).bg,
                            color: chipColor(chip.kind).fg,
                            borderRadius: '3px',
                            fontWeight: 500,
                        }"
                    >
                        <span :style="{ opacity: 0.7 }">{{ chip.label }}</span>
                        <span :style="{ fontFamily: 'var(--bm-font-mono)' }">{{ chip.target }}</span>
                    </span>
                </template>
                <span
                    v-if="actionChips.length === 0"
                    class="text-xs"
                    :style="{ color: 'var(--bm-text-4)' }"
                >
                    no actions configured
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span
                    class="text-xs tabular-nums"
                    :style="{
                        fontFamily: 'var(--bm-font-mono)',
                        color: 'var(--bm-text-4)',
                    }"
                >
                    Created {{ formatCreated(automation.created_at) }}
                </span>
                <template v-if="visibleProjects.length > 0">
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
                </template>
            </div>
        </div>

        <!-- Chevron affordance -->
        <div class="flex shrink-0 items-center gap-1">
            <ProjectQuickAssign
                v-if="projects && projects.length > 0 && syncUrl"
                :project-ids="projectIds ?? []"
                :options="projects"
                :sync-url="syncUrl"
                :reload-props="['automations']"
            />
            <PhCaretRight
                :size="16"
                class="transition-transform group-hover:translate-x-0.5"
                :style="{ color: 'var(--bm-text-4)' }"
            />
        </div>
    </div>
</template>
