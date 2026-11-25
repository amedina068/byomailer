<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    VALUELESS_ATTRIBUTE_OPERATORS,
    type AttributeFilter,
    type AttributeOperator,
    type AttributeValue,
} from '@/types/newsletters';

import { ATTRIBUTE_OPERATOR_OPTIONS, FIELD_CLASS } from './filterDefaults';
import { useFilterValues } from './useFilterValues';

defineProps<{
    index: number;
    attributeKeys: string[];
    processing: boolean;
}>();

const filter = defineModel<AttributeFilter>('filter', { required: true });

const NUMERIC_OPERATORS: AttributeOperator[] = ['gt', 'lt'];

const isValueless = (operator: AttributeOperator): boolean =>
    VALUELESS_ATTRIBUTE_OPERATORS.includes(operator);

const isNumeric = (operator: AttributeOperator): boolean =>
    NUMERIC_OPERATORS.includes(operator);

const {
    update,
    add: addRule,
    remove: removeRule,
} = useFilterValues<AttributeValue, AttributeFilter>(
    filter,
    () => ({ key: '', operator: 'equals', value: '' }),
    { keepOne: true },
);

const updateKey = (valueIndex: number, key: string): void => {
    update(valueIndex, { ...filter.value.values[valueIndex], key });
};

const updateOperator = (
    valueIndex: number,
    operator: AttributeOperator,
): void => {
    const current = filter.value.values[valueIndex];
    update(valueIndex, {
        ...current,
        operator,
        value: isValueless(operator) ? '' : current.value,
    });
};

const updateValue = (valueIndex: number, value: string): void => {
    update(valueIndex, { ...filter.value.values[valueIndex], value });
};
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="(value, valueIndex) in filter.values"
            :key="`attr-${index}-${valueIndex}`"
            class="grid gap-2 rounded-lg border border-dashed border-border p-3 md:grid-cols-[1fr_1fr_1fr_auto]"
        >
            <div class="grid gap-1">
                <label
                    class="text-sm font-medium"
                    :for="`attribute-key-${index}-${valueIndex}`"
                    >Attribute</label
                >
                <select
                    :id="`attribute-key-${index}-${valueIndex}`"
                    :value="value.key"
                    :disabled="processing"
                    :class="FIELD_CLASS"
                    @change="
                        updateKey(
                            valueIndex,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="" disabled>Select an attribute</option>
                    <option
                        v-for="key in attributeKeys"
                        :key="key"
                        :value="key"
                    >
                        {{ key }}
                    </option>
                    <option
                        v-if="value.key && !attributeKeys.includes(value.key)"
                        :value="value.key"
                    >
                        {{ value.key }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1">
                <label
                    class="text-sm font-medium"
                    :for="`attribute-operator-${index}-${valueIndex}`"
                    >Operator</label
                >
                <select
                    :id="`attribute-operator-${index}-${valueIndex}`"
                    :value="value.operator"
                    :disabled="processing"
                    :class="FIELD_CLASS"
                    @change="
                        updateOperator(
                            valueIndex,
                            ($event.target as HTMLSelectElement)
                                .value as AttributeOperator,
                        )
                    "
                >
                    <option
                        v-for="option in ATTRIBUTE_OPERATOR_OPTIONS"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1">
                <label
                    class="text-sm font-medium"
                    :for="`attribute-value-${index}-${valueIndex}`"
                    >Value</label
                >
                <input
                    v-if="!isValueless(value.operator)"
                    :id="`attribute-value-${index}-${valueIndex}`"
                    :type="isNumeric(value.operator) ? 'number' : 'text'"
                    :value="value.value"
                    :disabled="processing"
                    placeholder="active"
                    :class="FIELD_CLASS"
                    @input="
                        updateValue(
                            valueIndex,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <p
                    v-else
                    class="px-3 py-2 text-sm text-muted-foreground"
                >
                    No value needed
                </p>
            </div>

            <div class="flex items-end">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="processing"
                    @click="removeRule(valueIndex)"
                >
                    Remove
                </Button>
            </div>
        </div>

        <Button
            type="button"
            variant="outline"
            size="sm"
            class="mt-1"
            :disabled="processing"
            @click="addRule"
        >
            Add another attribute
        </Button>
    </div>
</template>
