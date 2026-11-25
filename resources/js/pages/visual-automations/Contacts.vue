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
import type { BreadcrumbItem } from '@/types';

interface Automation {
    id: number;
    name: string;
    enrollments_count: number;
}

interface StatTotals {
    contacts: number;
    completed: number;
    active: number;
}

const props = defineProps<{
    automation: Automation;
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
];

const filterOptions: FilterOption[] = [
    {
        key: 'all',
        label: 'All contacts',
        description: 'Everyone who has been enrolled in this automation.',
    },
    {
        key: 'active',
        label: 'Active',
        description: 'Contacts currently progressing through the workflow.',
    },
    {
        key: 'completed',
        label: 'Completed',
        description: 'Contacts who finished all steps.',
    },
];

const filterLink = (filter: string): string => {
    if (filter === 'all') {
        return automationContacts.index(props.automation.id).url;
    }

    return automationContacts.index(props.automation.id, {
        query: { filter },
    }).url;
};

const statCards = computed(() => [
    {
        key: 'contacts',
        label: 'Total enrolled',
        description: 'All contacts',
        value: props.stats.contacts,
        href: filterLink('all'),
    },
    {
        key: 'active',
        label: 'Active',
        description: 'In progress',
        value: props.stats.active,
        href: filterLink('active'),
    },
    {
        key: 'completed',
        label: 'Completed',
        description: 'Finished all steps',
        value: props.stats.completed,
        href: filterLink('completed'),
    },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${automation.name} · Contacts`" />

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
                    <p class="text-sm text-muted-foreground">
                        Visual Automation
                    </p>
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ automation.name }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        View all contacts enrolled in this automation.
                    </p>
                </div>
            </template>
        </PaginatedContactsTable>
    </AppLayout>
</template>
