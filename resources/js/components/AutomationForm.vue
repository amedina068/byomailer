<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AttributeConditionFields from '@/components/automations/AttributeConditionFields.vue';
import InputError from '@/components/InputError.vue';
import LinkNameInput from '@/components/links/LinkNameInput.vue';
import CreateTagModal from '@/components/tags/CreateTagModal.vue';
import { Button } from '@/components/ui/button';

type ConditionType =
    | 'contact_added_to_list'
    | 'removed_from_list'
    | 'tag_added'
    | 'removed_tag'
    | 'added_to_sequence'
    | 'sequence_finished'
    | 'attribute_changed'
    | 'link_clicked';
type AttributeOperator = 'changed_to' | 'changed';
type ActionType =
    | 'add_tag'
    | 'remove_tag'
    | 'add_list'
    | 'remove_list'
    | 'add_sequence';

interface SelectOption {
    id: number;
    name: string;
    status?: string;
}

const sequenceOptionLabel = (option: SelectOption): string =>
    option.status ? `${option.name} (${option.status})` : option.name;

interface AutomationData {
    name: string;
    condition: {
        type: ConditionType;
        attribute?: string | null;
        operator?: AttributeOperator | null;
        value?: string | number | null;
    };
    actions: Array<{
        type: ActionType;
        value?: string | number | null;
    }>;
}

const props = withDefaults(
    defineProps<{
        action: string;
        method?: 'post' | 'put';
        lists: SelectOption[];
        tags: SelectOption[];
        sequences: SelectOption[];
        attributes?: string[];
        linkNames?: string[];
        automation?: AutomationData | null;
    }>(),
    { attributes: () => [], linkNames: () => [] },
);

const showCreateTagModal = ref(false);
const createTagSource = ref<'action' | 'condition' | null>(null);

const formMethod = computed(() => props.method ?? 'post');
const methodOverride = computed(() =>
    formMethod.value.toLowerCase() === 'post'
        ? null
        : formMethod.value.toUpperCase(),
);

const initialAutomation = props.automation;
const stringValue = (value?: string | number | null) =>
    value === null || value === undefined ? '' : String(value);

const automationName = ref(initialAutomation?.name ?? '');

const conditionType = ref<ConditionType>(
    initialAutomation?.condition.type ??
        (props.lists.length ? 'contact_added_to_list' : 'tag_added'),
);

const defaultConditionValue = stringValue(initialAutomation?.condition.value);
const conditionValue = ref(defaultConditionValue);
const conditionAttribute = ref(initialAutomation?.condition.attribute ?? '');
const conditionOperator = ref<AttributeOperator>(
    initialAutomation?.condition.operator ?? 'changed_to',
);
const usesAttributeCondition = computed(
    () => conditionType.value === 'attribute_changed',
);
const usesLinkCondition = computed(
    () => conditionType.value === 'link_clicked',
);
const attributeOptions = computed(() => {
    const stored = initialAutomation?.condition.attribute;

    return stored && !props.attributes.includes(stored)
        ? [stored, ...props.attributes]
        : props.attributes;
});
const usesListCondition = computed(() =>
    ['contact_added_to_list', 'removed_from_list'].includes(
        conditionType.value,
    ),
);
const usesTagCondition = computed(() =>
    ['tag_added', 'removed_tag'].includes(conditionType.value),
);
const usesSequenceCondition = computed(() =>
    ['added_to_sequence', 'sequence_finished'].includes(conditionType.value),
);
const conditionValueOptions = computed(() => {
    if (usesAttributeCondition.value || usesLinkCondition.value) {
        return [];
    }

    if (usesSequenceCondition.value) {
        return props.sequences;
    }

    return usesListCondition.value ? props.lists : props.tags;
});

watch(
    [conditionType, conditionValueOptions],
    ([type, options], previous) => {
        if (type === 'attribute_changed' || type === 'link_clicked') {
            if (previous?.[0] && previous[0] !== type) {
                conditionValue.value = '';
            }

            if (
                type === 'attribute_changed' &&
                !attributeOptions.value.includes(conditionAttribute.value)
            ) {
                conditionAttribute.value = attributeOptions.value[0] ?? '';
            }

            return;
        }

        conditionAttribute.value = '';
        conditionOperator.value = 'changed_to';

        if (!options.length) {
            conditionValue.value = '';

            return;
        }

        const hasMatch = options.some(
            (option) => String(option.id) === conditionValue.value,
        );

        if (!hasMatch) {
            conditionValue.value = String(options[0].id);
        }
    },
    { immediate: true },
);

const initialAction = initialAutomation?.actions?.[0];
const defaultActionType = (): ActionType => {
    if (initialAction?.type) {
        return initialAction.type;
    }

    if (props.tags.length) {
        return 'add_tag';
    }

    if (props.lists.length) {
        return 'add_list';
    }

    if (props.sequences.length) {
        return 'add_sequence';
    }

    return 'add_tag';
};

const actionType = ref<ActionType>(defaultActionType());
const actionValue = ref(stringValue(initialAction?.value));
const usesTagOptions = computed(() =>
    ['add_tag', 'remove_tag'].includes(actionType.value),
);
const usesSequenceOptions = computed(() => actionType.value === 'add_sequence');
const actionValueOptions = computed(() => {
    if (usesSequenceOptions.value) {
        return props.sequences;
    }

    return usesTagOptions.value ? props.tags : props.lists;
});

watch(
    [actionType, actionValueOptions],
    ([, options]) => {
        if (!options.length) {
            actionValue.value = '';

            return;
        }

        const hasMatch = options.some(
            (option) => String(option.id) === actionValue.value,
        );

        if (!hasMatch) {
            actionValue.value = String(options[0].id);
        }
    },
    { immediate: true },
);

const addSequenceDraftError = computed(() => {
    if (
        !usesSequenceOptions.value ||
        actionValue.value === '' ||
        actionValue.value == null
    ) {
        return null;
    }

    const selected = props.sequences.find(
        (option) => String(option.id) === String(actionValue.value),
    );

    if (selected && selected.status && selected.status !== 'live') {
        return 'Automations can only add contacts to live sequences. Activate this sequence first.';
    }

    return null;
});

const canSubmit = computed(() => addSequenceDraftError.value === null);

const page = usePage();

const openCreateTagModal = (source: 'action' | 'condition') => {
    createTagSource.value = source;
    showCreateTagModal.value = true;
};

const handleTagCreated = () => {
    const createdTag = page.props.flash?.createdTag;

    if (createdTag) {
        if (createTagSource.value === 'action') {
            actionValue.value = String(createdTag.id);
        } else if (createTagSource.value === 'condition') {
            conditionValue.value = String(createdTag.id);
        }
    }

    createTagSource.value = null;
};
</script>

<template>
    <Form
        :action="action"
        method="post"
        class="space-y-6 rounded-xl border border-sidebar-border/60 bg-card p-6 shadow-sm dark:border-sidebar-border"
        v-slot="{ errors, processing }"
    >
        <input
            v-if="methodOverride"
            type="hidden"
            name="_method"
            :value="methodOverride"
        />
        <input type="hidden" name="type" value="simple" />

        <div class="grid gap-2">
            <label class="text-sm font-medium" for="name">Name</label>
            <input
                id="name"
                name="name"
                type="text"
                class="rounded-lg border border-border bg-background px-3 py-2 text-sm"
                placeholder="Welcome automation"
                required
                v-model="automationName"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <label class="text-sm font-medium" for="condition">Condition</label>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <select
                        id="condition"
                        name="condition[type]"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                        v-model="conditionType"
                    >
                        <option value="contact_added_to_list">
                            Contact added to list
                        </option>
                        <option value="removed_from_list">
                            Contact removed from list
                        </option>
                        <option value="tag_added">Tag added</option>
                        <option value="removed_tag">Tag removed</option>
                        <option
                            value="added_to_sequence"
                            v-if="props.sequences.length"
                        >
                            Added to sequence
                        </option>
                        <option
                            value="sequence_finished"
                            v-if="props.sequences.length"
                        >
                            Sequence finished
                        </option>
                        <option
                            value="attribute_changed"
                            v-if="attributeOptions.length"
                        >
                            Attribute changes
                        </option>
                        <option value="link_clicked">Link clicked</option>
                    </select>
                    <InputError :message="errors['condition.type']" />
                </div>
                <div v-if="usesAttributeCondition">
                    <select
                        name="condition[attribute]"
                        aria-label="Attribute"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                        v-model="conditionAttribute"
                    >
                        <option
                            v-for="attribute in attributeOptions"
                            :key="`attribute-${attribute}`"
                            :value="attribute"
                        >
                            {{ attribute }}
                        </option>
                    </select>
                    <InputError :message="errors['condition.attribute']" />
                </div>
                <div v-else-if="usesLinkCondition">
                    <LinkNameInput
                        id="automation-link-name"
                        v-model="conditionValue"
                        name="condition[value]"
                        :link-names="props.linkNames"
                        hint="Runs when a contact clicks a link with this name in an email. Set the name in the link settings of the editor."
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                        required
                    />
                    <InputError :message="errors['condition.value']" />
                </div>
                <div v-else>
                    <select
                        name="condition[value]"
                        aria-label="Condition value"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                        v-model="conditionValue"
                        @change="
                            conditionValue === '__create__' &&
                            usesTagCondition &&
                            (openCreateTagModal('condition'),
                            (conditionValue = conditionValueOptions.length
                                ? String(conditionValueOptions[0].id)
                                : ''))
                        "
                    >
                        <option
                            v-for="option in conditionValueOptions"
                            :key="`condition-${option.id}`"
                            :value="option.id"
                        >
                            {{
                                usesSequenceCondition
                                    ? sequenceOptionLabel(option)
                                    : option.name
                            }}
                        </option>
                        <option v-if="usesTagCondition" value="__create__">
                            + Create tag
                        </option>
                    </select>
                    <InputError :message="errors['condition.value']" />
                </div>
            </div>
            <AttributeConditionFields
                v-if="usesAttributeCondition"
                v-model:operator="conditionOperator"
                v-model:value="conditionValue"
                :errors="errors"
            />
            <p
                v-if="!attributeOptions.length"
                class="text-xs text-muted-foreground"
            >
                Attributes come from CSV imports, the API, or MCP. Add one to a
                contact to use this trigger.
            </p>
        </div>

        <div class="grid gap-3">
            <label class="text-sm font-medium" for="action-type">Actions</label>
            <div class="space-y-2">
                <div
                    class="grid grid-cols-2 gap-2 rounded-lg border border-border p-3"
                >
                    <div>
                        <select
                            id="action-type"
                            name="actions[0][type]"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                            v-model="actionType"
                        >
                            <option value="add_tag">Add tag</option>
                            <option value="add_list">Add to list</option>
                            <option value="remove_tag">Remove tag</option>
                            <option value="remove_list">
                                Remove from list
                            </option>
                            <option
                                value="add_sequence"
                                v-if="props.sequences.length"
                            >
                                Add to sequence
                            </option>
                        </select>
                        <InputError :message="errors['actions.0.type']" />
                    </div>
                    <div>
                        <select
                            name="actions[0][value]"
                            aria-label="Action value"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm"
                            v-model="actionValue"
                            @change="
                                actionValue === '__create__' &&
                                usesTagOptions &&
                                (openCreateTagModal('action'),
                                (actionValue = actionValueOptions.length
                                    ? String(actionValueOptions[0].id)
                                    : ''))
                            "
                        >
                            <option
                                v-for="option in actionValueOptions"
                                :key="`action-${option.id}`"
                                :value="option.id"
                            >
                                {{
                                    usesSequenceOptions
                                        ? sequenceOptionLabel(option)
                                        : option.name
                                }}
                            </option>
                            <option v-if="usesTagOptions" value="__create__">
                                + Create tag
                            </option>
                        </select>
                        <InputError :message="errors['actions.0.value']" />
                        <InputError
                            v-if="addSequenceDraftError"
                            :message="addSequenceDraftError"
                        />
                    </div>
                </div>
            </div>
        </div>

        <Button type="submit" :disabled="processing || !canSubmit">
            {{ processing ? 'Saving...' : 'Save automation' }}
        </Button>

        <CreateTagModal
            v-model:is-open="showCreateTagModal"
            @created="handleTagCreated"
        />
    </Form>
</template>
