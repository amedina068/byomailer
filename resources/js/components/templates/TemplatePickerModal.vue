<script setup lang="ts">
import { PhFileText } from '@phosphor-icons/vue';
import axios from 'axios';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

import EmailTemplateListController from '@/actions/App/Http/Controllers/EmailTemplateListController';
import HtmlPreview from '@/components/templates/HtmlPreview.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface EmailTemplate {
    id: number;
    name: string;
    html_content: string;
    is_default: boolean;
}

interface ListResponse {
    templates: {
        data: EmailTemplate[];
        next_page_url: string | null;
    };
}

const props = defineProps<{
    isOpen: boolean;
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
    select: [template: EmailTemplate];
}>();

const userTemplates = ref<EmailTemplate[]>([]);
const nextPageUrl = ref<string | null>(null);
const loading = ref(false);
const loadingMore = ref(false);
const loadError = ref<string | null>(null);

const sentinel = ref<HTMLDivElement | null>(null);
let observer: IntersectionObserver | null = null;

const fetchFromUrl = async (url: string): Promise<ListResponse | null> => {
    try {
        const { data } = await axios.get<ListResponse>(url);
        return data;
    } catch (error) {
        loadError.value = axios.isAxiosError(error)
            ? (error.response?.data?.message ?? 'Failed to load templates')
            : 'Failed to load templates';
        return null;
    }
};

const loadInitial = async (): Promise<void> => {
    loading.value = true;
    loadError.value = null;
    userTemplates.value = [];
    nextPageUrl.value = null;

    const data = await fetchFromUrl(EmailTemplateListController.url());

    if (data) {
        userTemplates.value = data.templates.data;
        nextPageUrl.value = data.templates.next_page_url;
    }

    loading.value = false;

    await nextTick();
    observeSentinel();
};

const loadMore = async (): Promise<void> => {
    if (loadingMore.value || !nextPageUrl.value) {
        return;
    }

    loadingMore.value = true;
    const data = await fetchFromUrl(nextPageUrl.value);

    if (data) {
        userTemplates.value.push(...data.templates.data);
        nextPageUrl.value = data.templates.next_page_url;
    }

    loadingMore.value = false;

    await nextTick();
    if (nextPageUrl.value && sentinel.value && observer) {
        observer.unobserve(sentinel.value);
        observer.observe(sentinel.value);
    }
};

const observeSentinel = (): void => {
    observer?.disconnect();
    observer = null;

    if (!sentinel.value || typeof IntersectionObserver === 'undefined') {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    loadMore();
                }
            }
        },
        { rootMargin: '200px' },
    );
    observer.observe(sentinel.value);
};

watch(
    () => props.isOpen,
    (open) => {
        if (open) {
            loadInitial();
        } else {
            observer?.disconnect();
            observer = null;
        }
    },
);

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

const selectTemplate = (template: EmailTemplate): void => {
    emit('select', template);
    emit('update:isOpen', false);
};

const hasAnyTemplate = () => userTemplates.value.length > 0;
</script>

<template>
    <Dialog
        :open="props.isOpen"
        @update:open="(open: boolean) => emit('update:isOpen', open)"
    >
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Choose a template</DialogTitle>
            </DialogHeader>

            <p class="text-sm text-muted-foreground">
                Pick a template. Its shell becomes locked and only the content
                slot remains editable.
            </p>

            <div
                v-if="loadError"
                class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600 dark:border-red-900 dark:bg-red-950 dark:text-red-400"
            >
                {{ loadError }}
                <button
                    type="button"
                    class="ml-2 underline"
                    @click="loadInitial"
                >
                    Retry
                </button>
            </div>

            <div v-else-if="loading" class="grid grid-cols-2 gap-3">
                <div
                    v-for="i in 4"
                    :key="i"
                    class="h-32 animate-pulse rounded-md bg-muted"
                />
            </div>

            <div
                v-else-if="hasAnyTemplate()"
                class="max-h-96 overflow-y-auto"
            >
                <div class="grid grid-cols-2 gap-3">
                    <button
                        v-for="template in userTemplates"
                        :key="template.id"
                        type="button"
                        class="overflow-hidden rounded-md border border-border text-left transition hover:border-primary hover:bg-muted/50"
                        @click="selectTemplate(template)"
                    >
                        <div class="aspect-video overflow-hidden bg-muted">
                            <HtmlPreview :html="template.html_content" />
                        </div>
                        <div class="p-2">
                            <p class="truncate text-sm font-medium">
                                {{ template.name }}
                                <span
                                    v-if="template.is_default"
                                    class="ml-1 font-mono text-xs text-primary"
                                >
                                    (default)
                                </span>
                            </p>
                        </div>
                    </button>
                </div>

                <div
                    v-if="nextPageUrl"
                    ref="sentinel"
                    class="mt-3 flex items-center justify-center py-3 text-xs text-muted-foreground"
                >
                    <span v-if="loadingMore">Loading more...</span>
                    <span v-else>&nbsp;</span>
                </div>
            </div>

            <div
                v-else
                class="flex flex-col items-center justify-center gap-3 rounded-md border border-dashed border-border py-12 text-center"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-full bg-muted"
                >
                    <PhFileText class="h-6 w-6 text-muted-foreground" weight="regular" />
                </div>
                <div>
                    <p class="text-sm font-medium">No templates yet</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Create a template first, then pick it here.
                    </p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
