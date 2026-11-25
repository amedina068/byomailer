<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { NodeProps } from '@vue-flow/core';
import { Handle, Position } from '@vue-flow/core';
import { GitBranch, Users } from 'lucide-vue-next';
import { computed, inject } from 'vue';

import { builderKey } from '../builderContext';
import {
    CONDITION_LABELS,
    type ConditionBranchType,
    type SelectOption,
} from '../types';

const props = defineProps<
    NodeProps<{
        condition_type?: ConditionBranchType;
        value?: number | null;
        contactCount?: number;
    }>
>();

const builder = inject(builderKey)!;

const availableConditionTypes = computed<ConditionBranchType[]>(() => {
    const types: ConditionBranchType[] = [];
    if (builder.tags.length) {
        types.push('has_tag', 'not_has_tag');
    }
    if (builder.lists.length) {
        types.push('in_list', 'not_in_list');
    }
    if (builder.topics?.length) {
        types.push('subscribed_to_topic', 'not_subscribed_to_topic');
    }
    if (builder.sequences.length) {
        types.push('in_sequence', 'not_in_sequence');
    }
    return types.length ? types : (['has_tag'] as ConditionBranchType[]);
});

const conditionType = computed<ConditionBranchType>(() => {
    if (
        props.data.condition_type &&
        availableConditionTypes.value.includes(props.data.condition_type)
    ) {
        return props.data.condition_type;
    }
    return availableConditionTypes.value[0];
});

const optionsForType = (type: ConditionBranchType): SelectOption[] => {
    switch (type) {
        case 'has_tag':
        case 'not_has_tag':
            return builder.tags;
        case 'in_list':
        case 'not_in_list':
            return builder.lists;
        case 'subscribed_to_topic':
        case 'not_subscribed_to_topic':
            return builder.topics ?? [];
        case 'in_sequence':
        case 'not_in_sequence':
            return builder.sequences;
        default:
            return [];
    }
};

const conditionOptions = computed(() => optionsForType(conditionType.value));

const optionLabel = (option: SelectOption): string => {
    const topicLike = option as SelectOption & { list_name?: string | null };
    if (topicLike.list_name) {
        return `${option.name} (${topicLike.list_name})`;
    }
    return option.name;
};
const selectedValue = computed(() =>
    props.data.value != null ? String(props.data.value) : '',
);

const setConditionType = (event: Event) => {
    const value = (event.target as HTMLSelectElement)
        .value as ConditionBranchType;
    const options = optionsForType(value);
    builder.updateNodeData(props.id, {
        condition_type: value,
        value: options.length ? options[0].id : null,
    });
};

const setConditionValue = (event: Event) => {
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
        class="w-[280px] rounded-xl border border-emerald-500/30 bg-card p-4 text-left shadow-sm dark:border-emerald-400/20"
    >
        <Handle
            type="target"
            :position="Position.Top"
            class="!h-3 !w-3 !border-2 !border-emerald-500 !bg-background"
        />

        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <div
                    class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-500/10"
                >
                    <GitBranch class="h-4 w-4 text-emerald-500" />
                </div>
                <p
                    class="text-xs font-semibold tracking-wider text-emerald-500 uppercase"
                >
                    Condition
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
                :for="`condition-type-${id}`"
                class="text-xs font-medium text-muted-foreground"
            >
                If contact
            </label>
            <select
                :id="`condition-type-${id}`"
                class="nodrag rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
                :disabled="!builder.isEditable"
                :value="conditionType"
                @change="setConditionType"
            >
                <option
                    v-for="type in availableConditionTypes"
                    :key="type"
                    :value="type"
                >
                    {{ CONDITION_LABELS[type] }}
                </option>
            </select>
        </div>

        <div v-if="conditionOptions.length" class="mt-3 grid gap-2 text-sm">
            <select
                aria-label="Target"
                class="nodrag rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
                :disabled="!builder.isEditable"
                :value="selectedValue"
                @change="setConditionValue"
            >
                <option
                    v-for="option in conditionOptions"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ optionLabel(option) }}
                </option>
            </select>
        </div>

        <div class="mt-4 flex items-center justify-between text-xs font-medium">
            <span class="text-emerald-500">Yes</span>
            <span class="text-red-400">No</span>
        </div>

        <Handle
            id="yes"
            type="source"
            :position="Position.Bottom"
            :style="{ left: '25%' }"
            class="!h-3 !w-3 !border-2 !border-emerald-500 !bg-background"
        />
        <Handle
            id="no"
            type="source"
            :position="Position.Bottom"
            :style="{ left: '75%' }"
            class="!h-3 !w-3 !border-2 !border-red-400 !bg-background"
        />
    </div>
</template>
