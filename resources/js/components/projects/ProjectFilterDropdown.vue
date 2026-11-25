<script setup lang="ts">
import { PhCaretDown, PhFolders } from '@phosphor-icons/vue';
import { computed } from 'vue';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    useProjectFilter,
    type ProjectOption,
} from '@/composables/useProjectFilter';

const props = withDefaults(
    defineProps<{
        projects: ProjectOption[];
        modelValue: string;
        align?: 'start' | 'end';
    }>(),
    { align: 'start' },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const filterModel = computed({
    get: () => props.modelValue,
    set: (value: string) => emit('update:modelValue', value),
});

const {
    normalizedProjectFilter,
    projectFilterLabel,
    setProjectFilter,
} = useProjectFilter(() => props.projects, filterModel);
</script>

<template>
    <DropdownMenu v-if="props.projects.length > 0">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-[var(--bm-surface-2)]"
                :style="{
                    backgroundColor: 'var(--bm-base)',
                    border: '1px solid var(--bm-border)',
                    borderRadius: 'var(--bm-radius)',
                    color:
                        normalizedProjectFilter === 'all'
                            ? 'var(--bm-text-3)'
                            : 'var(--bm-text-1)',
                }"
            >
                <PhFolders :size="14" />
                {{ projectFilterLabel }}
                <PhCaretDown :size="10" :style="{ color: 'var(--bm-text-4)' }" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent :align="props.align" class="w-56">
            <DropdownMenuRadioGroup
                :model-value="
                    normalizedProjectFilter === 'all'
                        ? 'all'
                        : String(normalizedProjectFilter)
                "
                @update:model-value="setProjectFilter"
            >
                <DropdownMenuRadioItem value="all">
                    All projects
                </DropdownMenuRadioItem>
                <DropdownMenuRadioItem
                    v-for="project in props.projects"
                    :key="project.id"
                    :value="String(project.id)"
                >
                    <span class="truncate">{{ project.name }}</span>
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
