<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { NodeProps } from '@vue-flow/core';
import { Handle, Position } from '@vue-flow/core';
import { Play, Users } from 'lucide-vue-next';
import { computed, inject } from 'vue';

import { builderKey } from '../builderContext';
import { ACTION_LABELS, type ActionType, type SelectOption } from '../types';

const props = defineProps<
    NodeProps<{
        action_type?: ActionType;
        value?: number | null;
        contactCount?: number;
    }>
>();

const builder = inject(builderKey)!;

const actionType = computed(() => props.data.action_type ?? 'add_tag');

const availableActionTypes = computed<ActionType[]>(() => {
    const types: ActionType[] = [];
    if (builder.tags.length) {
        types.push('add_tag', 'remove_tag');
    }
    if (builder.lists.length) {
        types.push('add_list', 'remove_list');
    }
    if (builder.sequences.length) {
        types.push('add_sequence');
    }
    types.push('unsubscribe_contact');
    return types;
});

const optionsForAction = (type: ActionType): SelectOption[] => {
    switch (type) {
        case 'add_tag':
        case 'remove_tag':
            return builder.tags;
        case 'add_list':
        case 'remove_list':
            return builder.lists;
        case 'add_sequence':
            return builder.sequences;
        default:
            return [];
    }
};

const actionOptions = computed(() => optionsForAction(actionType.value));
const selectedValue = computed(() =>
    props.data.value != null ? String(props.data.value) : '',
);

const needsValue = computed(() => actionType.value !== 'unsubscribe_contact');

const isAddSequence = computed(() => actionType.value === 'add_sequence');

const optionLabel = (option: SelectOption): string => {
    if (isAddSequence.value && option.status) {
        return `${option.name} (${option.status})`;
    }

    return option.name;
};

const draftSequenceWarning = computed(() => {
    if (!isAddSequence.value || props.data.value == null) {
        return null;
    }

    const selected = builder.sequences.find(
        (option) => option.id === props.data.value,
    );

    if (selected && selected.status && selected.status !== 'live') {
        return 'Automations can only add contacts to live sequences. Activate this sequence first.';
    }

    return null;
});

const setActionType = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value as ActionType;
    const options = optionsForAction(value);
    builder.updateNodeData(props.id, {
        action_type: value,
        value: options.length ? options[0].id : null,
    });
};

const setActionValue = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    builder.updateNodeData(props.id, {
        value: value === '' ? null : Number(value),
    });
};

const contactsUrl = computed(() =>
    builder.nodeContactsUrl ? builder.nodeContactsUrl(props.id) : null,
);

const navigateToContacts = () => {
    if (contactsUrl.value) {
        router.visit(contactsUrl.value);
    }
};

const removeNode = () => builder.confirmRemoveNode(props.id);
</script>

<template>
    <div
        class="w-[280px] rounded-xl border border-blue-500/30 bg-card p-4 text-left shadow-sm dark:border-blue-400/20"
    >
        <Handle
            type="target"
            :position="Position.Top"
            class="!h-3 !w-3 !border-2 !border-blue-500 !bg-background"
        />

        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <div
                    class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-500/10"
                >
                    <Play class="h-4 w-4 text-blue-500" />
                </div>
                <p
                    class="text-xs font-semibold tracking-wider text-blue-500 uppercase"
                >
                    Action
                </p>
                <button
                    v-if="data.contactCount"
                    type="button"
                    class="nodrag inline-flex items-center gap-0.5 text-[10px] font-medium text-slate-400 hover:text-primary"
                    @click="navigateToContacts"
                >
                    <Users class="h-3 w-3" />
                    {{ data.contactCount }}
                </button>
            </div>
            <button
                v-if="builder.isEditable"
                type="button"
                class="nodrag text-xs font-medium text-muted-foreground underline-offset-2 hover:underline"
                @click="removeNode"
            >
                Delete
            </button>
        </div>

        <div class="mt-3 grid gap-2 text-sm">
            <label
                :for="`action-type-${id}`"
                class="text-xs font-medium text-muted-foreground"
            >
                Do
            </label>
            <select
                :id="`action-type-${id}`"
                class="nodrag rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
                :disabled="!builder.isEditable"
                :value="actionType"
                @change="setActionType"
            >
                <option
                    v-for="type in availableActionTypes"
                    :key="type"
                    :value="type"
                >
                    {{ ACTION_LABELS[type] }}
                </option>
            </select>
        </div>

        <div
            v-if="needsValue && actionOptions.length"
            class="mt-3 grid gap-2 text-sm"
        >
            <select
                aria-label="Target"
                class="nodrag rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
                :disabled="!builder.isEditable"
                :value="selectedValue"
                @change="setActionValue"
            >
                <option
                    v-for="option in actionOptions"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ optionLabel(option) }}
                </option>
            </select>
            <p
                v-if="draftSequenceWarning"
                class="text-xs font-medium text-rose-500"
            >
                {{ draftSequenceWarning }}
            </p>
        </div>

        <Handle
            type="source"
            :position="Position.Bottom"
            class="!h-3 !w-3 !border-2 !border-blue-500 !bg-background"
        />
    </div>
</template>
