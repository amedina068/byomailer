<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import FiltersForm from '@/components/newsletters/FiltersForm.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAudienceCount } from '@/composables/useAudienceCount';
import {
    store as segmentsStore,
    update as segmentsUpdate,
} from '@/routes/segments';
import type { NewsletterFilter } from '@/types/newsletters';

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

interface SegmentInput {
    id: number;
    name: string;
    description: string | null;
    filters: NewsletterFilter[];
}

interface UsedBy {
    newsletters: { id: number; title: string }[];
    sequences: { id: number; name: string }[];
}

const props = withDefaults(
    defineProps<{
        segment?: SegmentInput | null;
        usedBy?: UsedBy | null;
        lists: MailList[];
        tags: TagOption[];
        topics?: TopicOption[];
        attributeKeys: string[];
        filterNewsletters?: NewsletterOption[];
        filterCountries?: string[];
        filterTimezones?: string[];
    }>(),
    {
        segment: null,
        usedBy: null,
        topics: () => [],
        filterNewsletters: () => [],
        filterCountries: () => [],
        filterTimezones: () => [],
    },
);

const isEdit = computed(() => props.segment !== null);

const form = useForm<{
    name: string;
    description: string;
    filters: NewsletterFilter[];
}>({
    name: props.segment?.name ?? '',
    description: props.segment?.description ?? '',
    filters: props.segment?.filters ?? [],
});

const newsletterCount = computed(() => props.usedBy?.newsletters.length ?? 0);
const liveSequenceCount = computed(() => props.usedBy?.sequences.length ?? 0);

const {
    count: matchCount,
    loading: matchCountLoading,
    fetch: fetchMatchCount,
    schedule: scheduleMatchCountFetch,
} = useAudienceCount();
let matchCountFetchEnabled = false;

onMounted(() => {
    matchCountFetchEnabled = true;
    fetchMatchCount(form.filters);
});

watch(
    () => form.filters,
    (filters) => {
        if (!matchCountFetchEnabled) {
            return;
        }

        scheduleMatchCountFetch(filters);
    },
    { deep: true },
);

const showConfirm = ref(false);

const submit = (): void => {
    if (isEdit.value && props.segment) {
        form.transform((data) => ({
            ...data,
            description: data.description || null,
        })).put(segmentsUpdate(props.segment.id).url, { preserveScroll: true });

        return;
    }

    form.transform((data) => ({
        ...data,
        description: data.description || null,
    })).post(segmentsStore().url, { preserveScroll: true });
};

const handleSave = (): void => {
    if (isEdit.value && liveSequenceCount.value > 0) {
        showConfirm.value = true;

        return;
    }

    submit();
};

const confirmSave = (): void => {
    showConfirm.value = false;
    submit();
};
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-1.5">
            <label
                for="segment-name"
                class="text-sm font-medium text-foreground"
            >
                Name
            </label>
            <input
                id="segment-name"
                v-model="form.name"
                type="text"
                placeholder="e.g. Active pro customers"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :disabled="form.processing"
                required
            />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-1.5">
            <label
                for="segment-description"
                class="text-sm font-medium text-foreground"
            >
                Description
                <span class="font-normal text-muted-foreground"
                    >— optional</span
                >
            </label>
            <input
                id="segment-description"
                v-model="form.description"
                type="text"
                maxlength="255"
                placeholder="What this audience represents"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :disabled="form.processing"
            />
            <InputError :message="form.errors.description" />
        </div>

        <div
            v-if="isEdit && (newsletterCount > 0 || liveSequenceCount > 0)"
            class="rounded-lg border px-4 py-3 text-sm"
            style="
                background-color: var(--bm-warning-dim);
                border-color: var(--bm-warning);
                color: var(--bm-text-1);
            "
        >
            Used by
            <span
                class="font-semibold tabular-nums"
                style="font-family: var(--bm-font-mono)"
                >{{ newsletterCount }}</span
            >
            scheduled {{ newsletterCount === 1 ? 'newsletter' : 'newsletters' }}
            and
            <span
                class="font-semibold tabular-nums"
                style="font-family: var(--bm-font-mono)"
                >{{ liveSequenceCount }}</span
            >
            live {{ liveSequenceCount === 1 ? 'sequence' : 'sequences' }}.
            Saving updates their audience immediately.
        </div>

        <div
            class="rounded-[var(--bm-radius)] border px-4 py-3 text-sm"
            style="
                background-color: var(--bm-base);
                border-color: var(--bm-border);
            "
        >
            <span v-if="matchCountLoading" style="color: var(--bm-text-3)">
                Calculating audience…
            </span>
            <span
                v-else-if="matchCount !== null"
                style="color: var(--bm-text-2)"
            >
                This segment currently matches
                <span
                    class="font-semibold tabular-nums"
                    style="
                        font-family: var(--bm-font-mono);
                        color: var(--bm-text-1);
                    "
                    >{{ matchCount.toLocaleString() }}</span
                >
                {{ matchCount === 1 ? 'contact' : 'contacts' }}.
            </span>
        </div>

        <FiltersForm
            v-model:filters="form.filters"
            :lists="lists"
            :tags="tags"
            :topics="topics"
            :attribute-keys="attributeKeys"
            :filter-newsletters="filterNewsletters"
            :filter-countries="filterCountries"
            :filter-timezones="filterTimezones"
            :processing="form.processing"
            :can-edit="true"
            :require-list-filter="false"
            :show-back-button="false"
            :errors="form.errors"
            context="segment"
            :save-label="isEdit ? 'Save segment' : 'Create segment'"
            @save="handleSave"
        />

        <Dialog :open="showConfirm" @update:open="showConfirm = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Update live sequences?</DialogTitle>
                    <DialogDescription>
                        This updates the audience for
                        {{ liveSequenceCount }} live
                        {{ liveSequenceCount === 1 ? 'sequence' : 'sequences' }}
                        immediately. Continue?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button :disabled="form.processing" @click="confirmSave">
                        {{ form.processing ? 'Saving…' : 'Save segment' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
