<script setup lang="ts">
import { computed } from 'vue';

import { useRelativeTime } from '@/composables/useRelativeTime';

interface TagRowData {
    id: number;
    name: string;
    contacts_count?: number;
    created_at: string | null;
}

const props = defineProps<{
    tag: TagRowData;
}>();

const { formatRelative } = useRelativeTime();

const createdLabel = computed(() =>
    formatRelative(props.tag.created_at, { emptyLabel: '—', withYear: true }),
);
</script>

<template>
    <tr class="bg-card/50">
        <td class="px-4 py-3 font-medium">{{ tag.name }}</td>
        <td class="px-4 py-3" style="font-family: var(--bm-font-mono)">
            {{ tag.contacts_count ?? 0 }}
        </td>
        <td
            class="px-4 py-3"
            style="font-family: var(--bm-font-mono); color: var(--bm-text-4)"
        >
            {{ createdLabel }}
        </td>
    </tr>
</template>
