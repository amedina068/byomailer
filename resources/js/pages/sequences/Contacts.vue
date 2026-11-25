<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

import type {
    FilterOption,
    PaginatedContacts,
} from '@/components/contacts/PaginatedContactsTable.vue';
import PaginatedContactsTable from '@/components/contacts/PaginatedContactsTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    show as sequenceShow,
    index as sequencesIndex,
} from '@/routes/sequences';
import sequenceContacts from '@/routes/sequences/contacts';
import type { BreadcrumbItem } from '@/types';

type ContactFilter = 'all' | 'completed' | 'unsubscribed' | 'clicked';

interface Sequence {
    id: number;
    name: string;
}

interface SequenceContact {
    id: number;
    name: string | null;
    email: string;
    unsubscribed_at?: string | null;
    created_at: string;
    latest_click?: {
        url?: string | null;
        clicked_at?: string | null;
    } | null;
}

interface StatTotals {
    contacts: number;
    completed: number;
    unsubscribed: number;
    clicked: number;
}

const props = defineProps<{
    sequence: Sequence;
    contacts: PaginatedContacts & { data: SequenceContact[] };
    filter: ContactFilter;
    stats: StatTotals;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sequences', href: sequencesIndex().url },
    { title: props.sequence.name, href: sequenceShow(props.sequence.id).url },
    {
        title: 'Contacts',
        href: sequenceContacts.index(props.sequence.id).url,
    },
];

const filterOptions: FilterOption[] = [
    {
        key: 'all',
        label: 'All contacts',
        description: 'Currently enrolled',
    },
    {
        key: 'completed',
        label: 'Completed',
        description: 'Finished the sequence',
    },
    {
        key: 'unsubscribed',
        label: 'Unsubscribed',
        description: 'Opted out via this sequence',
    },
    {
        key: 'clicked',
        label: 'Clicked',
        description: 'Engaged with a link',
    },
];

const filterLink = (filter: string): string => {
    if (filter === 'all') {
        return sequenceContacts.index(props.sequence.id).url;
    }

    return sequenceContacts.index(props.sequence.id, {
        query: { filter },
    }).url;
};

const statCards = computed(() => [
    {
        key: 'contacts',
        label: 'Contacts in sequence',
        description: 'Currently enrolled',
        value: props.stats.contacts,
        href: filterLink('all'),
    },
    {
        key: 'completed',
        label: 'Completed',
        description: 'Finished the sequence',
        value: props.stats.completed,
        href: filterLink('completed'),
    },
    {
        key: 'unsubscribed',
        label: 'Unsubscribed here',
        description: 'Left via this sequence',
        value: props.stats.unsubscribed,
        href: filterLink('unsubscribed'),
    },
    {
        key: 'clicked',
        label: 'Clicked a link',
        description: 'Engaged contacts',
        value: props.stats.clicked,
        href: filterLink('clicked'),
    },
]);

const showClickColumn = computed(() => props.filter === 'clicked');

const formatDateTime = (value?: string | null): string =>
    value ? new Date(value).toLocaleString() : '—';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${sequence.name} · Contacts`" />

        <PaginatedContactsTable
            :contacts="contacts"
            :filter="filter"
            :filter-options="filterOptions"
            :stat-cards="statCards"
            :filter-link="filterLink"
            show-status
        >
            <template #header>
                <div>
                    <p
                        class="text-[10px] font-semibold tracking-widest uppercase"
                        style="color: var(--bm-text-3)"
                    >
                        Sequence
                    </p>
                    <h1
                        class="mt-1 text-2xl font-semibold"
                        style="color: var(--bm-text-1)"
                    >
                        {{ sequence.name }}
                    </h1>
                    <p class="mt-1 text-sm" style="color: var(--bm-text-3)">
                        Drill into everyone who interacted with this sequence.
                    </p>
                </div>
            </template>

            <template v-if="showClickColumn" #extra-headers>
                <th
                    class="px-4 py-2.5 text-left text-[10px] font-semibold tracking-widest uppercase"
                    style="color: var(--bm-text-3)"
                >
                    Link
                </th>
            </template>

            <template v-if="showClickColumn" #extra-columns="{ contact }">
                <td class="px-4 py-3">
                    <div class="max-w-[220px] truncate text-sm">
                        <a
                            v-if="
                                (contact as SequenceContact).latest_click?.url
                            "
                            :href="
                                (contact as SequenceContact).latest_click!.url!
                            "
                            class="transition hover:underline"
                            style="color: var(--bm-accent)"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {{ (contact as SequenceContact).latest_click!.url }}
                        </a>
                        <span v-else style="color: var(--bm-text-3)">—</span>
                    </div>
                    <p
                        v-if="
                            (contact as SequenceContact).latest_click
                                ?.clicked_at
                        "
                        class="mt-1 text-xs tabular-nums"
                        style="
                            font-family: var(--bm-font-mono);
                            color: var(--bm-text-4);
                        "
                    >
                        {{
                            formatDateTime(
                                (contact as SequenceContact).latest_click
                                    ?.clicked_at,
                            )
                        }}
                    </p>
                </td>
            </template>
        </PaginatedContactsTable>
    </AppLayout>
</template>
