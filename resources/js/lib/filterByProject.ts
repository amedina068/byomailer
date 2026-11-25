export function normalizeProjectFilter(
    filter: string,
    validProjectIds: Set<number>,
): 'all' | number {
    if (filter === 'all') {
        return 'all';
    }

    const id = Number(filter);

    if (!Number.isInteger(id) || !validProjectIds.has(id)) {
        return 'all';
    }

    return id;
}

export function matchesProjectFilter(
    projectIds: number[] | undefined,
    filter: string,
    validProjectIds: Set<number>,
): boolean {
    const normalized = normalizeProjectFilter(filter, validProjectIds);

    if (normalized === 'all') {
        return true;
    }

    return projectIds?.includes(normalized) ?? false;
}
