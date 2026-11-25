<script setup lang="ts">
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import AttributeFilterEditor from '@/components/newsletters/filters/AttributeFilterEditor.vue';
import DateFilterEditor from '@/components/newsletters/filters/DateFilterEditor.vue';
import EngagementFilterEditor from '@/components/newsletters/filters/EngagementFilterEditor.vue';
import {
    CATEGORY_OPTIONS,
    DATE_OPERATOR_OPTIONS,
    ENGAGEMENT_OPERATOR_OPTIONS,
    FIELD_CLASS,
    MODE_CATEGORIES,
    buildDefaultFilter,
    filtersCopy,
    type FiltersContext,
} from '@/components/newsletters/filters/filterDefaults';
import LocationFilterEditor from '@/components/newsletters/filters/LocationFilterEditor.vue';
import { useFilterErrors } from '@/components/newsletters/filters/useFilterErrors';
import ValueListFilterEditor from '@/components/newsletters/filters/ValueListFilterEditor.vue';
import { Button } from '@/components/ui/button';
import { filterToSentence } from '@/lib/filterSentence';
import type {
    AttributeFilter,
    DateFilter,
    DateOperator,
    EngagementFilter,
    EngagementOperator,
    FilterCategory,
    FilterMode,
    ListFilter,
    LocationField,
    LocationFilter,
    LocationMode,
    NewsletterFilter,
    TagFilter,
    TopicFilter,
} from '@/types/newsletters';

interface MailList {
    id: number;
    name: string;
}

interface TagOption {
    id: number;
    name: string;
}

interface TopicOption {
    id: number;
    name: string;
    list_id: number;
    list_name: string | null;
}

interface NewsletterOption {
    id: number;
    title: string;
}

const props = withDefaults(
    defineProps<{
        lists: MailList[];
        tags: TagOption[];
        topics?: TopicOption[];
        attributeKeys: string[];
        filterNewsletters?: NewsletterOption[];
        filterCountries?: string[];
        filterTimezones?: string[];
        processing: boolean;
        canEdit: boolean;
        filtersError?: string;
        errors?: Partial<Record<string, string>>;
        context?: FiltersContext;
        requireListFilter?: boolean;
        showBackButton?: boolean;
        saveLabel?: string;
    }>(),
    {
        topics: () => [],
        filterNewsletters: () => [],
        filterCountries: () => [],
        filterTimezones: () => [],
        requireListFilter: true,
        showBackButton: true,
        saveLabel: 'Save filters & continue',
    },
);

const emit = defineEmits<{ (e: 'save'): void; (e: 'back'): void }>();

const filters = defineModel<NewsletterFilter[]>('filters', {
    default: () => [],
});

const MAX_FILTERS = 20;

const isEditing = ref(false);
let filtersSnapshot: NewsletterFilter[] = [];

const sentenceContext = computed(() => ({
    lists: props.lists,
    topics: props.topics,
    newsletters: props.filterNewsletters,
}));

const copy = computed(() =>
    filtersCopy(props.context, props.requireListFilter),
);

const hasFilters = computed(() => filters.value.length > 0);
const hasListFilter = computed(() =>
    filters.value.some((filter) => filter.category === 'lists'),
);
const canAddFilter = computed(() => filters.value.length < MAX_FILTERS);
const canSave = computed(() => {
    if (!props.canEdit || props.processing) {
        return false;
    }

    return props.requireListFilter ? hasListFilter.value : true;
});

const isAttributeFilter = (f: NewsletterFilter): f is AttributeFilter =>
    f.category === 'attributes';
const isValueListFilter = (
    f: NewsletterFilter,
): f is TagFilter | ListFilter | TopicFilter =>
    f.category === 'tags' || f.category === 'lists' || f.category === 'topics';
const isDateFilter = (f: NewsletterFilter): f is DateFilter =>
    f.category === 'date';
const isEngagementFilter = (f: NewsletterFilter): f is EngagementFilter =>
    f.category === 'engagement';
const isLocationFilter = (f: NewsletterFilter): f is LocationFilter =>
    f.category === 'location';

const showsModeSelect = (
    filter: NewsletterFilter,
): filter is AttributeFilter | TagFilter | ListFilter | TopicFilter =>
    MODE_CATEGORIES.includes(filter.category);

const replaceFilter = (index: number, value: NewsletterFilter): void => {
    filters.value[index] = value;
};

const enterEditMode = (): void => {
    if (!props.canEdit) {
        return;
    }

    filtersSnapshot = JSON.parse(JSON.stringify(filters.value));
    isEditing.value = true;
};

const addFilter = (): void => {
    if (!props.canEdit || !canAddFilter.value) {
        return;
    }

    filters.value.push(buildDefaultFilter('attributes', 'and'));
};

const addFirstFilter = (): void => {
    if (!props.canEdit) {
        return;
    }

    filtersSnapshot = JSON.parse(JSON.stringify(filters.value));
    isEditing.value = true;
    addFilter();
};

const removeFilter = (index: number): void => {
    if (!props.canEdit) {
        return;
    }

    filters.value.splice(index, 1);
};

const cancelEdit = (): void => {
    filters.value = JSON.parse(JSON.stringify(filtersSnapshot));
    isEditing.value = false;
};

const onCategoryChange = (index: number, category: FilterCategory): void => {
    const connector = filters.value[index]?.connector ?? 'and';
    filters.value[index] = buildDefaultFilter(category, connector);
};

const onConnectorChange = (
    filter: NewsletterFilter,
    connector: 'and' | 'or',
): void => {
    filter.connector = connector;
};

const onModeChange = (filter: NewsletterFilter, mode: FilterMode): void => {
    if (isLocationFilter(filter)) {
        filter.mode = (mode === 'none' ? 'none' : 'any') as LocationMode;

        return;
    }

    if (isAttributeFilter(filter) || isValueListFilter(filter)) {
        filter.mode = mode;
    }
};

const onDateOperatorChange = (
    filter: DateFilter,
    operator: DateOperator,
): void => {
    filter.operator = operator;

    if (operator === 'in_last' || operator === 'more_than') {
        filter.values = { days: filter.values.days ?? 30 };

        return;
    }

    if (operator === 'between') {
        filter.values = {
            from: filter.values.from ?? '',
            to: filter.values.to ?? '',
        };

        return;
    }

    filter.values = { date: filter.values.date ?? '' };
};

const onEngagementOperatorChange = (
    filter: EngagementFilter,
    operator: EngagementOperator,
): void => {
    filter.operator = operator;
};

const onLocationFieldChange = (
    filter: LocationFilter,
    field: LocationField,
): void => {
    filter.field = field;
    filter.values = [];
};

const handleSave = (): void => {
    if (!canSave.value) {
        return;
    }

    emit('save');
};

const { topLevelError, errorFor } = useFilterErrors({
    errors: () => props.errors ?? {},
    legacyError: () => props.filtersError,
    processing: () => props.processing,
    isEditing,
    openEditor: enterEditMode,
});

const handleBack = (): void => {
    emit('back');
};
</script>

<template>
    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
        >
            <div>
                <h2 class="text-lg font-semibold text-foreground">Filters</h2>
                <p class="text-sm text-muted-foreground">{{ copy.help }}</p>
            </div>
            <Button
                v-if="!isEditing && hasFilters && canEdit"
                type="button"
                variant="outline"
                size="sm"
                @click="enterEditMode"
            >
                Edit filters
            </Button>
        </div>

        <!-- View mode -->
        <template v-if="!isEditing">
            <div v-if="hasFilters" class="space-y-2">
                <div
                    v-for="(filter, index) in filters"
                    :key="index"
                    class="space-y-1"
                    data-filter-row
                >
                    <button
                        type="button"
                        :disabled="!canEdit"
                        class="w-full rounded-lg border border-border px-4 py-2.5 text-left text-sm text-foreground transition-colors"
                        :class="
                            canEdit
                                ? 'cursor-pointer hover:bg-muted/50'
                                : 'cursor-default'
                        "
                        @click="enterEditMode"
                    >
                        {{ filterToSentence(filter, index, sentenceContext) }}
                    </button>
                    <InputError :message="errorFor(index)" />
                </div>
            </div>

            <div
                v-else
                class="rounded-lg border border-dashed border-border px-4 py-8 text-center"
            >
                <p class="mb-4 text-sm text-muted-foreground">
                    {{ copy.empty }}
                </p>
                <Button
                    v-if="canEdit"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addFirstFilter"
                >
                    Add your first filter
                </Button>
            </div>

            <InputError :message="topLevelError" />

            <div class="flex flex-wrap gap-3">
                <Button
                    v-if="showBackButton"
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="handleBack"
                >
                    Back
                </Button>
                <Button type="button" :disabled="!canSave" @click="handleSave">
                    {{ processing ? 'Saving...' : saveLabel }}
                </Button>
            </div>
        </template>

        <!-- Edit mode -->
        <template v-else>
            <div v-if="hasFilters" class="space-y-4">
                <div
                    v-for="(filter, index) in filters"
                    :key="index"
                    class="space-y-4 rounded-lg border border-border p-4"
                    data-filter-row
                >
                    <div
                        class="grid gap-4 md:grid-cols-[repeat(4,minmax(0,1fr))] md:items-end"
                    >
                        <div v-if="index > 0" class="grid gap-1">
                            <label
                                class="text-sm font-medium"
                                :for="`filter-connector-${index}`"
                                >Connector</label
                            >
                            <select
                                :id="`filter-connector-${index}`"
                                :value="filter.connector"
                                :disabled="processing"
                                :class="FIELD_CLASS"
                                @change="
                                    onConnectorChange(
                                        filter,
                                        ($event.target as HTMLSelectElement)
                                            .value as 'and' | 'or',
                                    )
                                "
                            >
                                <option value="and">AND</option>
                                <option value="or">OR</option>
                            </select>
                        </div>

                        <div class="grid gap-1">
                            <label
                                class="text-sm font-medium"
                                :for="`filter-category-${index}`"
                                >Category</label
                            >
                            <select
                                :id="`filter-category-${index}`"
                                :value="filter.category"
                                :disabled="processing"
                                :class="FIELD_CLASS"
                                @change="
                                    onCategoryChange(
                                        index,
                                        ($event.target as HTMLSelectElement)
                                            .value as FilterCategory,
                                    )
                                "
                            >
                                <option
                                    v-for="option in CATEGORY_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Match mode: attributes / tags / lists / topics -->
                        <div v-if="showsModeSelect(filter)" class="grid gap-1">
                            <label
                                class="text-sm font-medium"
                                :for="`filter-mode-${index}`"
                                >Match mode</label
                            >
                            <select
                                :id="`filter-mode-${index}`"
                                :value="filter.mode"
                                :disabled="processing"
                                :class="FIELD_CLASS"
                                @change="
                                    onModeChange(
                                        filter,
                                        ($event.target as HTMLSelectElement)
                                            .value as FilterMode,
                                    )
                                "
                            >
                                <option value="all">Matches all</option>
                                <option value="any">Matches any</option>
                                <option value="none">Doesn't match any</option>
                            </select>
                        </div>

                        <!-- Operator: date -->
                        <div
                            v-else-if="isDateFilter(filter)"
                            class="grid gap-1"
                        >
                            <label
                                class="text-sm font-medium"
                                :for="`filter-date-operator-${index}`"
                                >Condition</label
                            >
                            <select
                                :id="`filter-date-operator-${index}`"
                                :value="filter.operator"
                                :disabled="processing"
                                :class="FIELD_CLASS"
                                @change="
                                    onDateOperatorChange(
                                        filter,
                                        ($event.target as HTMLSelectElement)
                                            .value as DateOperator,
                                    )
                                "
                            >
                                <option
                                    v-for="option in DATE_OPERATOR_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Operator: engagement -->
                        <div
                            v-else-if="isEngagementFilter(filter)"
                            class="grid gap-1"
                        >
                            <label
                                class="text-sm font-medium"
                                :for="`filter-engagement-operator-${index}`"
                                >Action</label
                            >
                            <select
                                :id="`filter-engagement-operator-${index}`"
                                :value="filter.operator"
                                :disabled="processing"
                                :class="FIELD_CLASS"
                                @change="
                                    onEngagementOperatorChange(
                                        filter,
                                        ($event.target as HTMLSelectElement)
                                            .value as EngagementOperator,
                                    )
                                "
                            >
                                <option
                                    v-for="option in ENGAGEMENT_OPERATOR_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Field: location -->
                        <div
                            v-else-if="isLocationFilter(filter)"
                            class="grid gap-1"
                        >
                            <label
                                class="text-sm font-medium"
                                :for="`filter-location-field-${index}`"
                                >Location type</label
                            >
                            <select
                                :id="`filter-location-field-${index}`"
                                :value="filter.field"
                                :disabled="processing"
                                :class="FIELD_CLASS"
                                @change="
                                    onLocationFieldChange(
                                        filter,
                                        ($event.target as HTMLSelectElement)
                                            .value as LocationField,
                                    )
                                "
                            >
                                <option value="country">Country</option>
                                <option value="timezone">Timezone</option>
                            </select>
                        </div>

                        <!-- Mode: location (any / none only) -->
                        <div v-if="isLocationFilter(filter)" class="grid gap-1">
                            <label
                                class="text-sm font-medium"
                                :for="`filter-location-mode-${index}`"
                                >Match mode</label
                            >
                            <select
                                :id="`filter-location-mode-${index}`"
                                :value="filter.mode"
                                :disabled="processing"
                                :class="FIELD_CLASS"
                                @change="
                                    onModeChange(
                                        filter,
                                        ($event.target as HTMLSelectElement)
                                            .value as FilterMode,
                                    )
                                "
                            >
                                <option value="any">Is any of</option>
                                <option value="none">Is none of</option>
                            </select>
                        </div>

                        <div
                            :class="[
                                index === 0 && !isLocationFilter(filter)
                                    ? 'col-span-2'
                                    : '',
                                'flex justify-end',
                            ]"
                        >
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :disabled="processing"
                                @click="removeFilter(index)"
                            >
                                Remove
                            </Button>
                        </div>
                    </div>

                    <AttributeFilterEditor
                        v-if="isAttributeFilter(filter)"
                        :filter="filter"
                        :index="index"
                        :attribute-keys="attributeKeys"
                        :processing="processing"
                        @update:filter="(value) => replaceFilter(index, value)"
                    />

                    <ValueListFilterEditor
                        v-else-if="isValueListFilter(filter)"
                        :filter="filter"
                        :index="index"
                        :processing="processing"
                        :tags="tags"
                        :lists="lists"
                        :topics="topics"
                        @update:filter="(value) => replaceFilter(index, value)"
                    />

                    <DateFilterEditor
                        v-else-if="isDateFilter(filter)"
                        :filter="filter"
                        :index="index"
                        :processing="processing"
                        @update:filter="(value) => replaceFilter(index, value)"
                    />

                    <EngagementFilterEditor
                        v-else-if="isEngagementFilter(filter)"
                        :filter="filter"
                        :index="index"
                        :newsletters="filterNewsletters"
                        :processing="processing"
                        @update:filter="(value) => replaceFilter(index, value)"
                    />

                    <LocationFilterEditor
                        v-else-if="isLocationFilter(filter)"
                        :filter="filter"
                        :index="index"
                        :countries="filterCountries"
                        :timezones="filterTimezones"
                        :processing="processing"
                        @update:filter="(value) => replaceFilter(index, value)"
                    />

                    <InputError :message="errorFor(index)" />
                </div>
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="!canEdit || processing || !canAddFilter"
                @click="addFilter"
            >
                Add filter
            </Button>
            <p v-if="!canAddFilter" class="text-xs text-muted-foreground">
                You can add up to {{ MAX_FILTERS }} filters.
            </p>

            <InputError :message="topLevelError" />

            <div class="flex flex-wrap gap-3">
                <Button
                    v-if="showBackButton"
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="handleBack"
                >
                    Back
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="cancelEdit"
                >
                    Cancel
                </Button>
                <Button type="button" :disabled="!canSave" @click="handleSave">
                    {{ processing ? 'Saving...' : saveLabel }}
                </Button>
            </div>
        </template>
    </div>
</template>
