<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhArchive, PhArrowCounterClockwise } from '@phosphor-icons/vue';
import { computed } from 'vue';

import { filterClause, type FilterSentenceContext } from '@/lib/filterSentence';
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
    segment: SegmentListItem;
    count?: number;
    viewUrl: string;
}>();

const emit = defineEmits<{ (e: 'archive'): void; (e: 'restore'): void }>();

const isArchived = computed(() => props.segment.archived_at != null);

/**
 * Index does not ship list/topic/newsletter names, so sentences fall back to
 * `List #id` for those categories. Attribute / date / engagement clauses still
 * render fully.
 */
const emptyContext: FilterSentenceContext = {
    lists: [],
    topics: [],
    newsletters: [],
};

const summary = computed((): string => {
    const filters = props.segment.filters ?? [];

    if (filters.length === 0) {
        return 'No filters';
    }

    const first = filterClause(filters[0], emptyContext);

    return filters.length === 1
        ? first
        : `${first} +${filters.length - 1} more`;
});

const formatRelative = (value?: string | null): string => {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }
    const diffMin = Math.round((date.getTime() - Date.now()) / 60_000);
    const abs = Math.abs(diffMin);
    if (abs < 1) {
        return 'just now';
    }
    if (abs < 60) {
        return `${abs}m ago`;
    }
    const hours = Math.round(abs / 60);
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

const updatedLabel = computed(() =>
    formatRelative(props.segment.updated_at ?? null),
);

const countLabel = computed((): string =>
    props.count === undefined ? '' : props.count.toLocaleString(),
);

const navigate = (): void => {
    router.visit(props.viewUrl);
};

/** Opens the segment only when the key event starts on the row itself. */
const navigateFromRow = (event: KeyboardEvent): void => {
    if (event.target !== event.currentTarget) {
        return;
    }

    event.preventDefault();
    navigate();
};

const onHover = (event: Event, on: boolean): void => {
    (event.currentTarget as HTMLElement).style.backgroundColor = on
        ? 'var(--bm-surface-2)'
        : 'transparent';
};

const onArchiveAction = (event: Event): void => {
    event.stopPropagation();

    if (isArchived.value) {
        emit('restore');

        return;
    }

    emit('archive');
};
</script>

<template>
    <tr
        class="group cursor-pointer transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
        style="border-bottom: 1px solid var(--bm-border)"
        tabindex="0"
        role="button"
        @click="navigate"
        @keydown.enter="navigateFromRow"
        @keydown.space="navigateFromRow"
        @mouseenter="(e) => onHover(e, true)"
        @mouseleave="(e) => onHover(e, false)"
    >
        <td class="px-4 py-3">
            <div class="flex flex-col gap-0.5">
                <span
                    class="truncate text-[13px] font-medium"
                    style="color: var(--bm-text-1)"
                >
                    {{ segment.name }}
                </span>
                <span
                    v-if="segment.description"
                    class="truncate text-xs"
                    style="color: var(--bm-text-3)"
                >
                    {{ segment.description }}
                </span>
            </div>
        </td>
        <td class="px-4 py-3">
            <span class="line-clamp-1 text-xs" style="color: var(--bm-text-3)">
                {{ summary }}
            </span>
        </td>
        <td
            class="px-4 py-3 text-right tabular-nums"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-1)"
        >
            <span
                v-if="count === undefined"
                class="inline-block h-3.5 w-10 animate-pulse rounded"
                style="background-color: var(--bm-surface-2)"
                aria-hidden="true"
            />
            <span v-else>{{ countLabel }}</span>
        </td>
        <td
            class="px-4 py-3 text-right text-[11px] tabular-nums"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-4)"
        >
            {{ updatedLabel }}
        </td>
        <td class="px-4 py-3 text-right">
            <button
                type="button"
                class="inline-flex items-center justify-center rounded p-1.5 transition-colors hover:bg-[var(--bm-surface-3)]"
                style="color: var(--bm-text-3)"
                :aria-label="isArchived ? 'Restore segment' : 'Archive segment'"
                @click="onArchiveAction"
            >
                <PhArrowCounterClockwise v-if="isArchived" :size="15" />
                <PhArchive v-else :size="15" />
            </button>
        </td>
    </tr>
</template>
