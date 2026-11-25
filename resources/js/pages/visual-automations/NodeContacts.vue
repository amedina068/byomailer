<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

import PaginatedContactsTable from '@/components/contacts/PaginatedContactsTable.vue';
import type {
    FilterOption,
    PaginatedContacts,
} from '@/components/contacts/PaginatedContactsTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    index as automationsIndex,
    show as automationShow,
} from '@/routes/visual-automations';
import automationContacts from '@/routes/visual-automations/contacts';
import nodeContacts from '@/routes/visual-automations/nodes/contacts';
import type { BreadcrumbItem } from '@/types';

interface Automation {
    id: number;
    name: string;
}

interface StatTotals {
    contacts: number;
    completed: number;
    active: number;
}

const props = defineProps<{
    automation: Automation;
    nodeId: string;
    nodeType: string;
    nodeLabel: string;
    contacts: PaginatedContacts;
    filter: string;
    stats: StatTotals;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Visual Automations', href: automationsIndex().url },
    {
        title: props.automation.name,
        href: automationShow(props.automation.id).url,
    },
    {
        title: 'Contacts',
        href: automationContacts.index(props.automation.id).url,
    },
    {
        title: props.nodeLabel,
        href: nodeContacts.index([props.automation.id, props.nodeId]).url,
    },
];

const filterOptions: FilterOption[] = [
    {
        key: 'all',
        label: 'All contacts',
        description: 'Everyone who reached this step.',
    },
    {
        key: 'active',
        label: 'Active',
        description: 'Currently waiting or pending at this step.',
    },
    {
        key: 'completed',
        label: 'Completed',
        description: 'Finished processing this step.',
    },
];

const filterLink = (filter: string): string => {
    const args: [number, string] = [props.automation.id, props.nodeId];

    if (filter === 'all') {
        return nodeContacts.index(args).url;
    }

    return nodeContacts.index(args, { query: { filter } }).url;
};

const statCards = computed(() => [
    {
        key: 'contacts',
        label: 'Total at step',
        description: 'All contacts',
        value: props.stats.contacts,
        href: filterLink('all'),
    },
    {
        key: 'active',
        label: 'Active',
        description: 'Pending or waiting',
        value: props.stats.active,
        href: filterLink('active'),
    },
    {
        key: 'completed',
        label: 'Completed',
        description: 'Processed this step',
        value: props.stats.completed,
        href: filterLink('completed'),
    },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head
            :title="`${automation.name} · ${nodeLabel} · Contacts`"
        />

        <PaginatedContactsTable
            :contacts="contacts"
            :filter="filter"
            :filter-options="filterOptions"
            :stat-cards="statCards"
            :filter-link="filterLink"
        >
            <template #header>
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{ nodeLabel }} step
                    </p>
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ automation.name }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Contacts at this {{ nodeLabel.toLowerCase() }} step.
                    </p>
                </div>
            </template>
        </PaginatedContactsTable>
    </AppLayout>
</template>
