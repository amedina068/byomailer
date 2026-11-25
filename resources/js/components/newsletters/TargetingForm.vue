<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import FiltersForm from '@/components/newsletters/FiltersForm.vue';
import { Button } from '@/components/ui/button';
import {
    filterToSentence,
    type FilterSentenceContext,
} from '@/lib/filterSentence';
import { edit as segmentEdit, index as segmentsIndex } from '@/routes/segments';
import type { NewsletterFilter, Segment } from '@/types/newsletters';

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
        segments: Segment[];
        lists: MailList[];
        tags: TagOption[];
        topics?: TopicOption[];
        attributeKeys: string[];
        filterNewsletters?: NewsletterOption[];
        filterCountries?: string[];
        filterTimezones?: string[];
        processing: boolean;
        canEdit: boolean;
        requireListFilter?: boolean;
        showBackButton?: boolean;
        saveLabel?: string;
        filtersError?: string;
        segmentError?: string;
        /** Optional POST endpoint that returns `{ count }` for a filter set. */
        previewUrl?: string;
    }>(),
    {
        topics: () => [],
        filterNewsletters: () => [],
        filterCountries: () => [],
        filterTimezones: () => [],
        requireListFilter: false,
        showBackButton: true,
        saveLabel: 'Save',
        filtersError: undefined,
        segmentError: undefined,
        previewUrl: undefined,
    },
);

const emit = defineEmits<{ (e: 'save'): void; (e: 'back'): void }>();

/** Custom filters live here; preserved across mode switches by never clearing. */
const filters = defineModel<NewsletterFilter[]>('filters', {
    default: () => [],
});
const segmentId = defineModel<number | null>('segmentId', { default: null });

type Mode = 'custom' | 'segment';

const mode = ref<Mode>(segmentId.value != null ? 'segment' : 'custom');

/** Draft hydration sets segmentId after mount — follow it into segment mode. */
watch(segmentId, (value) => {
    if (value != null) {
        mode.value = 'segment';
    }
});

const selectCustom = (): void => {
    mode.value = 'custom';
    segmentId.value = null;
};

const selectSegment = (): void => {
    mode.value = 'segment';

    if (segmentId.value == null && props.segments.length > 0) {
        segmentId.value = props.segments[0].id;
    }
};

const onSegmentSelect = (event: Event): void => {
    const value = (event.target as HTMLSelectElement).value;
    segmentId.value = value ? Number(value) : null;
};

const selectedSegment = computed<Segment | null>(
    () => props.segments.find((s) => s.id === segmentId.value) ?? null,
);

const sentenceContext = computed((): FilterSentenceContext => ({
    lists: props.lists.map((list) => ({ id: list.id, name: list.name })),
    topics: props.topics.map((topic) => ({
        id: topic.id,
        name: topic.name,
        list_name: topic.list_name,
    })),
    newsletters: props.filterNewsletters.map((newsletter) => ({
        id: newsletter.id,
        title: newsletter.title,
    })),
}));

const hasSegments = computed(() => props.segments.length > 0);

const canSaveSegment = computed(
    () => props.canEdit && !props.processing && segmentId.value != null,
);

const segmentCount = ref<number | null>(null);
const segmentCountLoading = ref(false);

const fetchSegmentCount = async (segment: Segment | null): Promise<void> => {
    if (!props.previewUrl || !segment) {
        segmentCount.value = null;

        return;
    }

    segmentCountLoading.value = true;

    try {
        const { data } = await axios.post<{ count: number }>(props.previewUrl, {
            filters: segment.filters,
        });

        segmentCount.value = data.count;
    } catch {
        segmentCount.value = null;
    } finally {
        segmentCountLoading.value = false;
    }
};

watch(
    selectedSegment,
    (segment) => {
        segmentCount.value = null;

        if (mode.value === 'segment') {
            fetchSegmentCount(segment);
        }
    },
    { immediate: true },
);

const handleSave = (): void => {
    if (!canSaveSegment.value) {
        return;
    }

    emit('save');
};
</script>

<template>
    <div class="space-y-5">
        <!-- Mode toggle -->
        <div
            class="grid gap-2 sm:grid-cols-2"
            role="radiogroup"
            aria-label="Audience source"
        >
            <button
                type="button"
                role="radio"
                :aria-checked="mode === 'custom'"
                class="rounded-lg border px-4 py-3 text-left transition-colors"
                :style="{
                    borderColor:
                        mode === 'custom'
                            ? 'var(--bm-accent)'
                            : 'var(--bm-border)',
                    backgroundColor:
                        mode === 'custom'
                            ? 'var(--bm-accent-bright-dim)'
                            : 'var(--bm-base)',
                }"
                @click="selectCustom"
            >
                <span
                    class="block text-sm font-medium"
                    style="color: var(--bm-text-1)"
                >
                    Custom filters
                </span>
                <span class="block text-xs" style="color: var(--bm-text-3)">
                    Build a one-off audience for this send.
                </span>
            </button>
            <button
                type="button"
                role="radio"
                :aria-checked="mode === 'segment'"
                class="rounded-lg border px-4 py-3 text-left transition-colors"
                :style="{
                    borderColor:
                        mode === 'segment'
                            ? 'var(--bm-accent)'
                            : 'var(--bm-border)',
                    backgroundColor:
                        mode === 'segment'
                            ? 'var(--bm-accent-bright-dim)'
                            : 'var(--bm-base)',
                }"
                @click="selectSegment"
            >
                <span
                    class="block text-sm font-medium"
                    style="color: var(--bm-text-1)"
                >
                    Saved segment
                </span>
                <span class="block text-xs" style="color: var(--bm-text-3)">
                    Reuse a named, dynamically-evaluated audience.
                </span>
            </button>
        </div>

        <!-- Custom mode -->
        <FiltersForm
            v-if="mode === 'custom'"
            v-model:filters="filters"
            :lists="lists"
            :tags="tags"
            :topics="topics"
            :attribute-keys="attributeKeys"
            :filter-newsletters="filterNewsletters"
            :filter-countries="filterCountries"
            :filter-timezones="filterTimezones"
            :processing="processing"
            :can-edit="canEdit"
            :require-list-filter="requireListFilter"
            :show-back-button="showBackButton"
            :save-label="saveLabel"
            :filters-error="filtersError"
            @save="emit('save')"
            @back="emit('back')"
        />

        <!-- Segment mode -->
        <div v-else class="space-y-4">
            <template v-if="hasSegments">
                <div class="grid gap-1.5">
                    <label
                        for="targeting-segment"
                        class="text-sm font-medium text-foreground"
                    >
                        Segment
                    </label>
                    <select
                        id="targeting-segment"
                        :value="segmentId ?? ''"
                        :disabled="processing || !canEdit"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @change="onSegmentSelect"
                    >
                        <option value="" disabled>Select a segment</option>
                        <option
                            v-for="segment in segments"
                            :key="segment.id"
                            :value="segment.id"
                        >
                            {{ segment.name }}
                        </option>
                    </select>
                    <InputError :message="segmentError" />
                </div>

                <div
                    v-if="selectedSegment"
                    class="space-y-3 rounded-lg border px-4 py-3"
                    style="
                        background-color: var(--bm-base);
                        border-color: var(--bm-border);
                    "
                >
                    <div class="flex items-center justify-between gap-3">
                        <span
                            class="text-[10px] font-semibold uppercase tracking-widest"
                            style="color: var(--bm-text-3)"
                        >
                            Audience
                        </span>
                        <span
                            v-if="segmentCountLoading"
                            class="text-xs"
                            style="color: var(--bm-text-3)"
                        >
                            Calculating…
                        </span>
                        <span
                            v-else-if="segmentCount !== null"
                            class="text-xs tabular-nums"
                            style="
                                font-family: var(--bm-font-mono);
                                color: var(--bm-text-1);
                            "
                        >
                            {{ segmentCount.toLocaleString() }}
                            {{ segmentCount === 1 ? 'contact' : 'contacts' }}
                        </span>
                    </div>

                    <div class="space-y-1.5">
                        <p
                            v-for="(filter, index) in selectedSegment.filters"
                            :key="index"
                            class="rounded-md border px-3 py-1.5 text-sm"
                            style="
                                background-color: var(--bm-surface);
                                border-color: var(--bm-border);
                                color: var(--bm-text-2);
                            "
                        >
                            {{ filterToSentence(filter, index, sentenceContext) }}
                        </p>
                        <p
                            v-if="selectedSegment.filters.length === 0"
                            class="text-sm"
                            style="color: var(--bm-text-3)"
                        >
                            This segment has no filters.
                        </p>
                    </div>

                    <Link
                        :href="segmentEdit(selectedSegment.id).url"
                        class="inline-block text-xs font-medium transition hover:underline"
                        style="color: var(--bm-accent)"
                    >
                        Edit segment
                    </Link>
                </div>
            </template>

            <div
                v-else
                class="rounded-lg border border-dashed px-4 py-8 text-center"
                style="border-color: var(--bm-border)"
            >
                <p class="mb-3 text-sm" style="color: var(--bm-text-3)">
                    You have no saved segments yet.
                </p>
                <Button as-child variant="outline" size="sm">
                    <Link :href="segmentsIndex().url">Manage segments</Link>
                </Button>
            </div>

            <div class="flex flex-wrap gap-3">
                <Button
                    v-if="showBackButton"
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="emit('back')"
                >
                    Back
                </Button>
                <Button
                    type="button"
                    :disabled="!canSaveSegment"
                    @click="handleSave"
                >
                    {{ processing ? 'Saving…' : saveLabel }}
                </Button>
            </div>
        </div>
    </div>
</template>
