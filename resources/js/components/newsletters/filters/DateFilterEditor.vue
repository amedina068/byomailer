<script setup lang="ts">
import { computed } from 'vue';

import {
    RELATIVE_DATE_OPERATORS,
    type DateFilter,
} from '@/types/newsletters';

import { FIELD_CLASS } from './filterDefaults';
import { patchValues, setValues } from './useFilterValues';

defineProps<{
    index: number;
    processing: boolean;
}>();

const filter = defineModel<DateFilter>('filter', { required: true });

const isRelative = computed((): boolean =>
    RELATIVE_DATE_OPERATORS.includes(filter.value.operator),
);

const isBetween = computed((): boolean => filter.value.operator === 'between');

const isSingleDate = computed(
    (): boolean => !isRelative.value && !isBetween.value,
);

const updateDate = (date: string): void => {
    setValues(filter, { date });
};

const updateDays = (raw: string): void => {
    const parsed = Number(raw);
    setValues(filter, {
        days: Number.isNaN(parsed) || parsed < 1 ? 1 : Math.floor(parsed),
    });
};

const updateFrom = (from: string): void => {
    patchValues(filter, { from });
};

const updateTo = (to: string): void => {
    patchValues(filter, { to });
};
</script>

<template>
    <div
        class="grid gap-3 rounded-lg border border-dashed border-border p-3 md:grid-cols-2"
    >
        <div v-if="isSingleDate" class="grid gap-1">
            <label
                class="text-sm font-medium"
                :for="`date-value-${index}`"
                >Contact created date</label
            >
            <input
                :id="`date-value-${index}`"
                type="date"
                :value="filter.values.date ?? ''"
                :disabled="processing"
                :class="[FIELD_CLASS, 'font-mono']"
                @input="
                    updateDate(($event.target as HTMLInputElement).value)
                "
            />
        </div>

        <div v-if="isRelative" class="grid gap-1">
            <label
                class="text-sm font-medium"
                :for="`date-days-${index}`"
                >Number of days</label
            >
            <input
                :id="`date-days-${index}`"
                type="number"
                min="1"
                :value="filter.values.days ?? ''"
                :disabled="processing"
                placeholder="30"
                :class="[FIELD_CLASS, 'font-mono']"
                @input="updateDays(($event.target as HTMLInputElement).value)"
            />
        </div>

        <template v-if="isBetween">
            <div class="grid gap-1">
                <label
                    class="text-sm font-medium"
                    :for="`date-from-${index}`"
                    >From date</label
                >
                <input
                    :id="`date-from-${index}`"
                    type="date"
                    :value="filter.values.from ?? ''"
                    :disabled="processing"
                    :class="[FIELD_CLASS, 'font-mono']"
                    @input="
                        updateFrom(($event.target as HTMLInputElement).value)
                    "
                />
            </div>
            <div class="grid gap-1">
                <label
                    class="text-sm font-medium"
                    :for="`date-to-${index}`"
                    >To date</label
                >
                <input
                    :id="`date-to-${index}`"
                    type="date"
                    :value="filter.values.to ?? ''"
                    :disabled="processing"
                    :class="[FIELD_CLASS, 'font-mono']"
                    @input="
                        updateTo(($event.target as HTMLInputElement).value)
                    "
                />
            </div>
        </template>
    </div>
</template>
