import { computed, type ComputedRef, type Ref } from 'vue';

import { normalizeProjectFilter } from '@/lib/filterByProject';

export interface ProjectOption {
    id: number;
    name: string;
}

export interface UseProjectFilterReturn {
    validProjectIds: ComputedRef<Set<number>>;
    normalizedProjectFilter: ComputedRef<'all' | number>;
    projectFilterLabel: ComputedRef<string>;
    setProjectFilter: (value: unknown) => void;
}

export function useProjectFilter(
    projects: () => ProjectOption[],
    projectFilter: Ref<string>,
): UseProjectFilterReturn {
    const validProjectIds = computed(
        () => new Set(projects().map((project) => project.id)),
    );

    const normalizedProjectFilter = computed(() =>
        normalizeProjectFilter(projectFilter.value, validProjectIds.value),
    );

    const projectFilterLabel = computed(() => {
        const normalized = normalizedProjectFilter.value;
        if (normalized === 'all') {
            return 'All projects';
        }
        return (
            projects().find((project) => project.id === normalized)?.name ??
            'All projects'
        );
    });

    const setProjectFilter = (value: unknown): void => {
        if (typeof value === 'string') {
            projectFilter.value = value;
        }
    };

    return {
        validProjectIds,
        normalizedProjectFilter,
        projectFilterLabel,
        setProjectFilter,
    };
}
