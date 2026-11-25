<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    PhCheckCircle,
    PhClock,
    PhFlask,
    PhPaperPlaneTilt,
    PhPencilSimple,
    PhWarning,
} from '@phosphor-icons/vue';
import { computed, type Component } from 'vue';

import ProjectBadge from '@/components/projects/ProjectBadge.vue';
import ProjectQuickAssign from '@/components/projects/ProjectQuickAssign.vue';
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

interface NewsletterRowData {
    id: number;
    title?: string | null;
    subject: string;
    status: string;
    scheduled_at?: string | null;
    sent_at?: string | null;
    created_at?: string | null;
    list?: NewsletterList | null;
    ab_test_enabled?: boolean | null;
    metrics: NewsletterMetrics;
}

const props = defineProps<{
    newsletter: NewsletterRowData;
    statuses: NewsletterStatusOption[];
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

const formatRate = (rate: number | null | undefined): string =>
    rate !== null && rate !== undefined ? `${rate}%` : '—';

const formatRelative = (value?: string | null): string | null => {
    if (!value) {
        return null;
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return null;
    }
    const diffMs = date.getTime() - Date.now();
    const diffMin = Math.round(diffMs / 60_000);
    const abs = Math.abs(diffMin);
    const past = diffMin < 0;
    if (abs < 1) {
        return 'just now';
    }
    if (abs < 60) {
        return past ? `${abs}m ago` : `in ${abs}m`;
    }
    const hours = Math.round(abs / 60);
    if (hours < 24) {
        return past ? `${hours}h ago` : `in ${hours}h`;
    }
    const days = Math.round(hours / 24);
    if (days < 30) {
        return past ? `${days}d ago` : `in ${days}d`;
    }
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

// The server sends the labels. The icon and the colors stay keyed by the status value.
const STATUS_STYLES: Record<string, { icon: Component; fg: string; bg: string }> = {
    sent: { icon: PhCheckCircle, fg: 'var(--bm-success)', bg: 'var(--bm-success-dim)' },
    queued: { icon: PhPaperPlaneTilt, fg: 'var(--bm-info)', bg: 'var(--bm-info-dim)' },
    scheduled: { icon: PhClock, fg: 'var(--bm-warning)', bg: 'var(--bm-warning-dim)' },
    ab_testing: { icon: PhFlask, fg: 'var(--bm-accent)', bg: 'var(--bm-accent-bright-dim)' },
    failed: { icon: PhWarning, fg: 'var(--bm-error)', bg: 'var(--bm-error-dim)' },
    draft: { icon: PhPencilSimple, fg: 'var(--bm-text-3)', bg: 'var(--bm-surface-2)' },
};

const statusMeta = computed(() => {
    const status = props.newsletter.status;
    const label = props.statuses.find((option) => option.value === status)?.label ?? status;

    return { label, ...(STATUS_STYLES[status] ?? STATUS_STYLES.draft) };
});

const sendMeta = computed((): { label: string; value: string } | null => {
    const n = props.newsletter;
    if (n.sent_at) {
        const value = formatRelative(n.sent_at);
        return value ? { label: 'Sent', value } : null;
    }
    if (n.scheduled_at) {
        const value = formatRelative(n.scheduled_at);
        return value ? { label: 'Scheduled', value } : null;
    }
    return null;
});

const hasDistinctSubject = computed(
    () =>
        Boolean(props.newsletter.title) &&
        Boolean(props.newsletter.subject) &&
        props.newsletter.title !== props.newsletter.subject,
);

const navigate = (): void => {
    router.visit(props.viewUrl);
};

const onHover = (event: Event, on: boolean): void => {
    (event.currentTarget as HTMLElement).style.backgroundColor = on
        ? 'var(--bm-surface-2)'
        : 'transparent';
};
</script>

<template>
    <tr
        class="group cursor-pointer transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--bm-accent)]"
        style="border-bottom: 1px solid var(--bm-border);"
        tabindex="0"
        role="button"
        @click="navigate"
        @keydown.enter.self.prevent="navigate"
        @keydown.space.self.prevent="navigate"
        @mouseenter="(e) => onHover(e, true)"
        @mouseleave="(e) => onHover(e, false)"
    >
        <td class="px-4 py-3">
            <div class="flex flex-col gap-0.5">
                <div class="flex items-center gap-2">
                    <span
                        class="truncate text-[13px] font-medium"
                        style="color: var(--bm-text-1);"
                    >
                        {{ newsletter.title || newsletter.subject || 'Untitled newsletter' }}
                    </span>
                    <span
                        v-if="newsletter.ab_test_enabled"
                        class="shrink-0 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider"
                        style="font-family: var(--bm-font-mono); background-color: var(--bm-accent-bright-dim); color: var(--bm-accent); border-radius: 3px;"
                    >
                        A/B
                    </span>
                </div>
                <span
                    v-if="hasDistinctSubject"
                    class="truncate text-xs"
                    style="color: var(--bm-text-3);"
                >
                    {{ newsletter.subject }}
                </span>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span
                        v-if="newsletter.list?.name"
                        class="text-[11px]"
                        style="color: var(--bm-text-4);"
                    >
                        → {{ newsletter.list.name }}
                    </span>
                    <span
                        v-if="sendMeta"
                        class="text-[11px] tabular-nums"
                        style="font-family: var(--bm-font-mono); color: var(--bm-text-4);"
                    >
                        · {{ sendMeta.label }} {{ sendMeta.value }}
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
        </td>
        <td class="px-4 py-3">
            <span
                class="inline-flex items-center gap-1.5 px-2 py-0.5"
                :style="{
                    backgroundColor: statusMeta.bg,
                    color: statusMeta.fg,
                    fontFamily: 'var(--bm-font-mono)',
                    fontSize: '10px',
                    fontWeight: 500,
                    borderRadius: '3px',
                }"
            >
                <component :is="statusMeta.icon" :size="11" />
                {{ statusMeta.label }}
            </span>
        </td>
        <td
            class="px-4 py-3 text-right tabular-nums"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-2);"
        >
            {{ formatNumber(newsletter.metrics.total) }}
        </td>
        <td
            class="px-4 py-3 text-right tabular-nums"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-2);"
        >
            {{ formatNumber(newsletter.metrics.sent) }}
        </td>
        <td
            class="px-4 py-3 text-right tabular-nums"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
        >
            {{ formatRate(newsletter.metrics.open_rate) }}
        </td>
        <td
            class="px-4 py-3 text-right tabular-nums"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-1);"
        >
            {{ formatRate(newsletter.metrics.click_rate) }}
        </td>
        <td
            class="px-4 py-3 text-right tabular-nums"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-2);"
        >
            {{ formatRate(newsletter.metrics.unsubscribe_rate) }}
        </td>
        <td
            v-if="projects && projects.length > 0 && syncUrl"
            class="px-2 py-3 text-right"
        >
            <ProjectQuickAssign
                :project-ids="projectIds ?? []"
                :options="projects"
                :sync-url="syncUrl"
                :reload-props="['newsletters']"
            />
        </td>
    </tr>
</template>
