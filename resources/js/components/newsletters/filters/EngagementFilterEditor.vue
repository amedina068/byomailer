<script setup lang="ts">
import { computed } from 'vue';

import type { EngagementFilter } from '@/types/newsletters';

import { FIELD_CLASS } from './filterDefaults';
import { patchValues } from './useFilterValues';

defineProps<{
    index: number;
    newsletters: { id: number; title: string }[];
    processing: boolean;
}>();

const filter = defineModel<EngagementFilter>('filter', { required: true });

const isLastNDays = computed(
    (): boolean => filter.value.values.within === 'last_n_days',
);

const updateNewsletter = (raw: string): void => {
    patchValues(filter, {
        newsletter_id: raw === '' ? null : Number(raw),
    });
};

const updateWithin = (raw: string): void => {
    const within = raw === 'last_n_days' ? 'last_n_days' : 'ever';
    patchValues(filter, {
        within,
        days: within === 'last_n_days' ? (filter.value.values.days ?? 7) : null,
    });
};

const updateDays = (raw: string): void => {
    const parsed = Number(raw);
    patchValues(filter, {
        days: Number.isNaN(parsed) || parsed < 1 ? 1 : Math.floor(parsed),
    });
};
</script>

<template>
    <div
        class="grid gap-3 rounded-lg border border-dashed border-border p-3 md:grid-cols-3"
    >
        <div class="grid gap-1">
            <label
                class="text-sm font-medium"
                :for="`engagement-target-${index}`"
                >Newsletter</label
            >
            <select
                :id="`engagement-target-${index}`"
                :value="filter.values.newsletter_id ?? ''"
                :disabled="processing"
                :class="FIELD_CLASS"
                @change="
                    updateNewsletter(
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">Any newsletter</option>
                <option
                    v-for="newsletter in newsletters"
                    :key="newsletter.id"
                    :value="newsletter.id"
                >
                    {{ newsletter.title }}
                </option>
            </select>
        </div>

        <div class="grid gap-1">
            <label
                class="text-sm font-medium"
                :for="`engagement-within-${index}`"
                >Time window</label
            >
            <select
                :id="`engagement-within-${index}`"
                :value="filter.values.within"
                :disabled="processing"
                :class="FIELD_CLASS"
                @change="
                    updateWithin(($event.target as HTMLSelectElement).value)
                "
            >
                <option value="ever">At any time</option>
                <option value="last_n_days">In the last N days</option>
            </select>
        </div>

        <div v-if="isLastNDays" class="grid gap-1">
            <label
                class="text-sm font-medium"
                :for="`engagement-days-${index}`"
                >Number of days</label
            >
            <input
                :id="`engagement-days-${index}`"
                type="number"
                min="1"
                :value="filter.values.days ?? ''"
                :disabled="processing"
                placeholder="7"
                :class="[FIELD_CLASS, 'font-mono']"
                @input="updateDays(($event.target as HTMLInputElement).value)"
            />
        </div>
    </div>
</template>
