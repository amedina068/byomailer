<script setup lang="ts">
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import type { LocationFilter } from '@/types/newsletters';

import { FIELD_CLASS } from './filterDefaults';
import { useFilterValues } from './useFilterValues';

const props = defineProps<{
    index: number;
    countries: string[];
    timezones: string[];
    processing: boolean;
}>();

const filter = defineModel<LocationFilter>('filter', { required: true });

/** Distinct values available for the currently-selected location field. */
const fieldOptions = computed((): string[] =>
    filter.value.field === 'country' ? props.countries : props.timezones,
);

/** Field options not yet picked, used to populate each "add" select. */
const availableOptions = computed((): string[] =>
    fieldOptions.value.filter(
        (option) => !filter.value.values.includes(option),
    ),
);

const hasOptions = computed((): boolean => fieldOptions.value.length > 0);

const fieldNoun = computed((): string =>
    filter.value.field === 'country' ? 'country' : 'timezone',
);

const {
    update,
    add: addValue,
    remove: removeValue,
} = useFilterValues<string, LocationFilter>(filter, () => '');

const updateValue = (valueIndex: number, value: string): void => {
    if (value === '') {
        return;
    }

    update(valueIndex, value);
};
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="(value, valueIndex) in filter.values"
            :key="`location-${index}-${valueIndex}`"
            class="flex gap-2 rounded-lg border border-dashed border-border p-3"
        >
            <div class="grid flex-1 gap-1">
                <label
                    class="text-sm font-medium"
                    :for="`location-value-${index}-${valueIndex}`"
                >
                    {{ filter.field === 'country' ? 'Country' : 'Timezone' }}
                </label>
                <select
                    :id="`location-value-${index}-${valueIndex}`"
                    :value="value"
                    :disabled="processing || !hasOptions"
                    :class="[FIELD_CLASS, 'font-mono']"
                    @change="
                        updateValue(
                            valueIndex,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="" disabled>
                        Select a {{ fieldNoun }}
                    </option>
                    <option v-if="value" :value="value">{{ value }}</option>
                    <option
                        v-for="option in availableOptions"
                        :key="option"
                        :value="option"
                    >
                        {{ option }}
                    </option>
                </select>
            </div>
            <div class="flex items-end">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="processing"
                    @click="removeValue(valueIndex)"
                >
                    Remove
                </Button>
            </div>
        </div>

        <p v-if="!hasOptions" class="text-xs text-muted-foreground">
            No {{ fieldNoun }} data is available on your contacts yet.
        </p>

        <Button
            type="button"
            variant="outline"
            size="sm"
            class="mt-1"
            :disabled="processing || !hasOptions"
            @click="addValue"
        >
            Add another {{ fieldNoun }}
        </Button>
    </div>
</template>
