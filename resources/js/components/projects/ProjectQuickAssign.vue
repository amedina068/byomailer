<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PhFolders } from '@phosphor-icons/vue';
import { ref, watch } from 'vue';

import ProjectMultiSelect from '@/components/projects/ProjectMultiSelect.vue';
import { toast } from '@/composables/useToast';

interface ProjectOption {
    id: number;
    name: string;
}

const props = defineProps<{
    projectIds: number[];
    options: ProjectOption[];
    syncUrl: string;
    reloadProps?: string[];
}>();

const localIds = ref<number[]>([...props.projectIds]);

let serverIds = [...props.projectIds];
let inFlight = false;
let queued: number[] | null = null;

const sameIds = (a: number[], b: number[]): boolean => {
    if (a.length !== b.length) {
        return false;
    }

    const set = new Set(a);

    return b.every((id) => set.has(id));
};

watch(
    () => props.projectIds,
    (ids) => {
        serverIds = [...ids];

        if (!inFlight && queued === null) {
            localIds.value = [...ids];
        }
    },
);

const send = (next: number[]): void => {
    inFlight = true;

    router.post(
        props.syncUrl,
        { project_ids: next },
        {
            preserveScroll: true,
            preserveState: true,
            ...(props.reloadProps ? { only: props.reloadProps } : {}),
            onError: () => {
                queued = null;
                localIds.value = [...serverIds];
                toast.error('Could not update projects. Try again.');
            },
            onFinish: () => {
                inFlight = false;

                if (queued === null) {
                    return;
                }

                const nextQueued = queued;
                queued = null;

                if (sameIds(nextQueued, serverIds)) {
                    localIds.value = [...serverIds];

                    return;
                }

                send(nextQueued);
            },
        },
    );
};

const sync = (next: number[]): void => {
    localIds.value = [...next];

    if (inFlight) {
        queued = [...next];

        return;
    }

    send(next);
};
</script>

<template>
    <div class="inline-flex" @click.stop>
        <ProjectMultiSelect
            :model-value="localIds"
            :options="options"
            @update:model-value="sync"
        >
            <template #trigger="{ count }">
                <button
                    type="button"
                    class="relative inline-flex items-center justify-center p-1.5 transition-colors hover:bg-[var(--bm-elevated)]"
                    :style="{
                        borderRadius: 'var(--bm-radius)',
                        color: 'var(--bm-text-3)',
                    }"
                    :aria-label="
                        count > 0
                            ? `Assigned to ${count} project${count === 1 ? '' : 's'}`
                            : 'Assign to projects'
                    "
                >
                    <PhFolders :size="15" />
                    <span
                        v-if="count > 0"
                        class="absolute -right-0.5 -top-0.5 flex h-3.5 min-w-3.5 items-center justify-center px-0.5 tabular-nums"
                        style="
                            font-family: var(--bm-font-mono);
                            font-size: 9px;
                            font-weight: 500;
                            border-radius: var(--bm-radius-sm);
                            background-color: var(--bm-elevated);
                            color: var(--bm-text-2);
                        "
                    >
                        {{ count }}
                    </span>
                </button>
            </template>
        </ProjectMultiSelect>
    </div>
</template>
