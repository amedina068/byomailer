<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import AutomationForm from '@/components/AutomationForm.vue';
import ConfirmDeleteAutomationModal from '@/components/automations/ConfirmDeleteAutomationModal.vue';
import ConfirmStatusToggleModal from '@/components/automations/ConfirmStatusToggleModal.vue';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as indexRoute } from '@/routes/automations';

type SelectOption = { id: number; name: string };

type ConditionType =
    | 'contact_added_to_list'
    | 'removed_from_list'
    | 'tag_added'
    | 'removed_tag'
    | 'added_to_sequence'
    | 'sequence_finished'
    | 'attribute_changed'
    | 'link_clicked';

type AutomationPayload = {
    id: number;
    name: string;
    type: string;
    condition: {
        type: ConditionType;
        attribute?: string | null;
        operator?: 'changed_to' | 'changed' | null;
        value?: string | number | null;
    };
    actions: Array<{
        type:
            | 'add_tag'
            | 'remove_tag'
            | 'add_list'
            | 'remove_list'
            | 'add_sequence';
        value?: string | number | null;
    }>;
    is_live: boolean;
};

const props = defineProps<{
    automation: AutomationPayload;
    lists: SelectOption[];
    tags: SelectOption[];
    sequences: SelectOption[];
    attributes: string[];
    linkNames: string[];
    updateRoute: string;
    destroyRoute: string;
    statusRoute: string;
}>();

const confirmingToggle = ref(false);
const confirmingDelete = ref(false);

const toggleStatus = () => {
    router.patch(
        props.statusRoute,
        { is_live: !props.automation.is_live },
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmingToggle.value = false;
            },
        },
    );
};

const deleteAutomation = () => {
    router.delete(props.destroyRoute, {
        onSuccess: () => {
            confirmingDelete.value = false;
        },
    });
};

const breadcrumbs = [
    { title: 'Automations', href: indexRoute().url },
    { title: props.automation.name, href: indexRoute().url },
    { title: 'Edit', href: indexRoute().url },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit automation · ${props.automation.name}`" />

        <div
            class="mb-6 flex items-center justify-between rounded-xl border border-sidebar-border/60 bg-card p-4 shadow-sm dark:border-sidebar-border"
        >
            <label
                class="flex cursor-pointer items-center gap-3"
                @click.prevent="confirmingToggle = true"
            >
                <Switch
                    :model-value="props.automation.is_live"
                    class="pointer-events-none"
                />
                <span class="text-sm font-medium">Live</span>
            </label>
            <button
                class="text-sm text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                @click="confirmingDelete = true"
            >
                Delete automation
            </button>
        </div>

        <AutomationForm
            :action="props.updateRoute"
            method="put"
            :lists="props.lists"
            :tags="props.tags"
            :sequences="props.sequences"
            :attributes="props.attributes"
            :link-names="props.linkNames"
            :automation="props.automation"
        />

        <ConfirmStatusToggleModal
            v-model:is-open="confirmingToggle"
            :is-live="props.automation.is_live"
            @confirm="toggleStatus"
        />

        <ConfirmDeleteAutomationModal
            v-model:is-open="confirmingDelete"
            @confirm="deleteAutomation"
        />
    </AppLayout>
</template>
