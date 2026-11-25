<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhPencilSimple, PhTrash } from '@phosphor-icons/vue';
import { computed } from 'vue';

import { useRelativeTime } from '@/composables/useRelativeTime';
import { index as automationsIndex } from '@/routes/automations';
import { index as newslettersIndex } from '@/routes/newsletters';
import { index as sequencesIndex } from '@/routes/sequences';
import { index as visualAutomationsIndex } from '@/routes/visual-automations';

interface ProjectRowData {
    id: number;
    uuid: string;
    name: string;
    newsletters_count?: number;
    sequences_count?: number;
    automations_count?: number;
    visual_automations_count?: number;
    created_at: string | null;
}

const props = defineProps<{
    project: ProjectRowData;
}>();

const emit = defineEmits<{
    (e: 'edit'): void;
    (e: 'delete'): void;
}>();

const { formatRelative } = useRelativeTime();

const createdLabel = computed(() =>
    formatRelative(props.project.created_at, { emptyLabel: '—', withYear: true }),
);

const resourceLinks = computed(
    (): { label: string; count: number; href: string }[] => {
        const query = { project: String(props.project.id) };

        return [
            {
                label: 'newsletter',
                count: props.project.newsletters_count ?? 0,
                href: newslettersIndex({ query }).url,
            },
            {
                label: 'sequence',
                count: props.project.sequences_count ?? 0,
                href: sequencesIndex({ query }).url,
            },
            {
                label: 'automation',
                count: props.project.automations_count ?? 0,
                href: automationsIndex({ query }).url,
            },
            {
                label: 'visual automation',
                count: props.project.visual_automations_count ?? 0,
                href: visualAutomationsIndex({ query }).url,
            },
        ];
    },
);

const onHover = (event: Event, on: boolean): void => {
    (event.currentTarget as HTMLElement).style.backgroundColor = on
        ? 'var(--bm-surface-2)'
        : 'transparent';
};
</script>

<template>
    <tr
        class="transition-colors"
        style="border-bottom: 1px solid var(--bm-border)"
        @mouseenter="(e) => onHover(e, true)"
        @mouseleave="(e) => onHover(e, false)"
    >
        <td class="px-4 py-3">
            <div class="flex flex-col gap-0.5">
                <span
                    class="truncate text-[13px] font-medium"
                    style="color: var(--bm-text-1)"
                >
                    {{ project.name }}
                </span>
                <span class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                    <Link
                        v-for="entry in resourceLinks"
                        :key="entry.label"
                        :href="entry.href"
                        class="text-[11px] tabular-nums transition-colors hover:underline"
                        :style="{
                            fontFamily: 'var(--bm-font-mono)',
                            color:
                                entry.count === 0
                                    ? 'var(--bm-text-4)'
                                    : 'var(--bm-text-3)',
                        }"
                    >
                        {{ entry.count.toLocaleString() }} {{ entry.label
                        }}{{ entry.count === 1 ? '' : 's' }}
                    </Link>
                </span>
            </div>
        </td>
        <td
            class="px-4 py-3 text-right text-[11px] tabular-nums"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-4)"
        >
            {{ createdLabel }}
        </td>
        <td class="px-4 py-3 text-right">
            <div class="inline-flex items-center gap-1">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded p-1.5 transition-colors hover:bg-[var(--bm-elevated)]"
                    style="color: var(--bm-text-3)"
                    aria-label="Rename project"
                    @click="emit('edit')"
                >
                    <PhPencilSimple :size="15" />
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded p-1.5 transition-colors hover:bg-[var(--bm-elevated)]"
                    style="color: var(--bm-text-3)"
                    aria-label="Delete project"
                    @click="emit('delete')"
                >
                    <PhTrash :size="15" />
                </button>
            </div>
        </td>
    </tr>
</template>
