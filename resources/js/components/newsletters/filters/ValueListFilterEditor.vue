<script setup lang="ts">
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import type { ListFilter, TagFilter, TopicFilter } from '@/types/newsletters';

import { FIELD_CLASS } from './filterDefaults';
import { useFilterValues } from './useFilterValues';

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

interface MailListOption {
    id: number;
    name: string;
}

const props = defineProps<{
    index: number;
    processing: boolean;
    tags: TagOption[];
    lists: MailListOption[];
    topics: TopicOption[];
}>();

const filter = defineModel<TagFilter | ListFilter | TopicFilter>('filter', {
    required: true,
});

const isTag = computed((): boolean => filter.value.category === 'tags');
const isList = computed((): boolean => filter.value.category === 'lists');

const noun = computed((): string => {
    if (isTag.value) {
        return 'tag';
    }

    if (isList.value) {
        return 'list';
    }

    return 'topic';
});

const hasOptions = computed((): boolean => {
    if (isTag.value) {
        return props.tags.length > 0;
    }

    if (isList.value) {
        return props.lists.length > 0;
    }

    return props.topics.length > 0;
});

const emptyValue = computed((): string | number => (isTag.value ? '' : 0));

const {
    update,
    add: addValue,
    remove: removeValue,
} = useFilterValues<string | number, TagFilter | ListFilter | TopicFilter>(
    filter,
    () => emptyValue.value,
    { keepOne: true },
);

const updateValue = (valueIndex: number, raw: string): void => {
    update(valueIndex, isTag.value ? raw : Number(raw));
};
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="(value, valueIndex) in filter.values"
            :key="`${noun}-${index}-${valueIndex}`"
            class="flex gap-2 rounded-lg border border-dashed border-border p-3"
        >
            <div class="grid flex-1 gap-1">
                <label
                    class="text-sm font-medium capitalize"
                    :for="`${noun}-select-${index}-${valueIndex}`"
                    >{{ noun }}</label
                >
                <select
                    :id="`${noun}-select-${index}-${valueIndex}`"
                    :value="value"
                    :disabled="processing || !hasOptions"
                    :class="[FIELD_CLASS, 'w-full']"
                    @change="
                        updateValue(
                            valueIndex,
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <template v-if="isTag">
                        <option value="" disabled>Select a tag</option>
                        <option
                            v-for="tag in tags"
                            :key="tag.id"
                            :value="tag.name"
                        >
                            {{ tag.name }}
                        </option>
                    </template>
                    <template v-else-if="isList">
                        <option :value="0" disabled>Select a list</option>
                        <option
                            v-for="list in lists"
                            :key="list.id"
                            :value="list.id"
                        >
                            {{ list.name }}
                        </option>
                    </template>
                    <template v-else>
                        <option :value="0" disabled>Select a topic</option>
                        <option
                            v-for="topic in topics"
                            :key="topic.id"
                            :value="topic.id"
                        >
                            {{ topic.name
                            }}<template v-if="topic.list_name">
                                — {{ topic.list_name }}</template
                            >
                        </option>
                    </template>
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
            <template v-if="isTag"
                >Create tags first to use them in filters.</template
            >
            <template v-else-if="isList"
                >Create lists first to use them in filters.</template
            >
            <template v-else
                >Create topics on a list first to filter by them.</template
            >
        </p>

        <Button
            type="button"
            variant="outline"
            size="sm"
            class="mt-1"
            :disabled="processing"
            @click="addValue"
        >
            Add another {{ noun }}
        </Button>
    </div>
</template>
