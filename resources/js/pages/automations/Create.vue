<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AutomationForm from '@/components/AutomationForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    create as createRoute,
    index as indexRoute,
} from '@/routes/automations';

type SelectOption = { id: number; name: string };

const props = defineProps<{
    lists: SelectOption[];
    tags: SelectOption[];
    sequences: SelectOption[];
    attributes: string[];
    storeRoute: string;
}>();

const breadcrumbs = [
    { title: 'Automations', href: indexRoute().url },
    { title: 'Create', href: createRoute().url },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create automation" />

        <AutomationForm
            :action="props.storeRoute"
            method="post"
            :lists="props.lists"
            :tags="props.tags"
            :sequences="props.sequences"
            :attributes="props.attributes"
        />
    </AppLayout>
</template>
