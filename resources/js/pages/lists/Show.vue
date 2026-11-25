<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import TopicModal from '@/components/topics/TopicModal.vue';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as contactsIndex } from '@/routes/contacts';
import {
    edit as listsEdit,
    index as listsIndex,
    show as listsShow,
} from '@/routes/lists';
import type { BreadcrumbItem } from '@/types';

interface MailList {
    id: number;
    uuid: string;
    name: string;
    contacts_count?: number;
    topics_count?: number;
    created_at: string;
}

interface TopicRow {
    id: number;
    list_id: number;
    name: string;
    description: string | null;
    subscribed_count: number;
}

interface ImportStatus {
    value: string;
    label: string;
    in_progress: boolean;
}

interface ContactImport {
    id: number;
    status: string;
    original_filename: string;
    total_count: number;
    processed_count: number;
    skipped_count: number;
    progress: number;
    error_message?: string | null;
    created_at: string;
}

const props = defineProps<{
    list: MailList;
    imports: ContactImport[];
    importStatuses: ImportStatus[];
    topics: TopicRow[];
}>();

const topicModalOpen = ref(false);
const topicConfirmOpen = ref(false);
const pendingTopicDelete = ref<TopicRow | null>(null);

const listOptions = computed(() => [
    { id: props.list.id, name: props.list.name },
]);

function askTopicDelete(topic: TopicRow): void {
    pendingTopicDelete.value = topic;
    topicConfirmOpen.value = true;
}

function confirmTopicDelete(): void {
    const topic = pendingTopicDelete.value;
    if (!topic) {
        return;
    }

    router.delete(`/lists/${props.list.id}/topics/${topic.id}`, {
        preserveScroll: true,
        onFinish: () => {
            topicConfirmOpen.value = false;
            pendingTopicDelete.value = null;
        },
    });
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Lists',
        href: listsIndex().url,
    },
    {
        title: props.list.name,
        href: listsShow(props.list.id).url,
    },
];

const formatDate = (value?: string | null): string => {
    return value ? new Date(value).toLocaleDateString() : '—';
};

const formatDateTime = (value?: string | null): string => {
    return value ? new Date(value).toLocaleString() : '—';
};

const hasImports = computed(() => props.imports.length > 0);

const importStatusByValue = computed(
    () => new Map(props.importStatuses.map((status) => [status.value, status])),
);

// Reload the imports while one is in the queue, so the progress updates.
const importInProgress = computed(() =>
    props.imports.some(
        (importItem) =>
            importStatusByValue.value.get(importItem.status)?.in_progress ??
            false,
    ),
);

const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['imports', 'list'] },
    { autoStart: false },
);

watch(
    importInProgress,
    (inProgress) => {
        if (inProgress) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

const statusStyles: Record<string, { class: string; progressClass: string }> = {
    pending: {
        class: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400',
        progressClass: 'bg-yellow-500',
    },
    processing: {
        class: 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400',
        progressClass: 'bg-blue-500',
    },
    completed: {
        class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        progressClass: 'bg-emerald-500',
    },
    failed: {
        class: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400',
        progressClass: 'bg-red-500',
    },
};

const getStatusConfig = (status: string) => ({
    label: importStatusByValue.value.get(status)?.label ?? status,
    ...(statusStyles[status] ?? statusStyles.pending),
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`List · ${list.name}`" />

        <section class="space-y-6">
            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0 space-y-1">
                        <h1
                            class="text-2xl font-semibold break-words text-foreground"
                        >
                            {{ list.name }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Created {{ formatDate(list.created_at) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div
                            class="rounded-lg border border-border px-4 py-2 text-center"
                        >
                            <p
                                class="font-mono text-2xl font-semibold text-foreground tabular-nums"
                            >
                                {{ list.contacts_count ?? 0 }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Contacts
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-border px-4 py-2 text-center"
                        >
                            <p
                                class="font-mono text-2xl font-semibold text-foreground tabular-nums"
                            >
                                {{ list.topics_count ?? 0 }}
                            </p>
                            <p class="text-xs text-muted-foreground">Topics</p>
                        </div>
                        <Link
                            class="rounded-lg border border-border px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted"
                            :href="listsEdit(list.id).url"
                        >
                            Edit
                        </Link>
                        <Link
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                            :href="
                                contactsIndex({ query: { lists: list.id } }).url
                            "
                        >
                            View contacts
                        </Link>
                    </div>
                </div>
            </div>

            <div
                class="rounded-[var(--bm-radius-md)] border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">
                            Topics
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Let subscribers opt out of individual content
                            categories without leaving the list.
                        </p>
                    </div>
                    <Button
                        v-if="topics.length > 0"
                        variant="outline"
                        size="sm"
                        @click="topicModalOpen = true"
                    >
                        New topic
                    </Button>
                </div>

                <div class="mt-4">
                    <div
                        v-if="topics.length === 0"
                        class="rounded-[var(--bm-radius-md)] border border-dashed border-border p-8 text-center"
                    >
                        <p class="text-sm text-muted-foreground">
                            No topics on this list yet.
                        </p>
                        <Button
                            class="mt-4"
                            size="sm"
                            @click="topicModalOpen = true"
                        >
                            Create first topic
                        </Button>
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-xs text-muted-foreground"
                            >
                                <th class="py-2 font-medium whitespace-nowrap">
                                    Name
                                </th>
                                <th class="py-2 font-medium whitespace-nowrap">
                                    Description
                                </th>
                                <th
                                    class="py-2 text-right font-medium whitespace-nowrap"
                                >
                                    Subscribers
                                </th>
                                <th class="py-2 whitespace-nowrap"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="topic in topics"
                                :key="topic.id"
                                class="border-b border-border"
                            >
                                <td class="py-2 font-medium">
                                    {{ topic.name }}
                                </td>
                                <td
                                    class="max-w-xs truncate py-2 text-xs text-muted-foreground"
                                >
                                    {{ topic.description ?? '—' }}
                                </td>
                                <td
                                    class="py-2 text-right font-mono tabular-nums"
                                >
                                    {{ topic.subscribed_count }}
                                </td>
                                <td class="py-2 text-right">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="askTopicDelete(topic)"
                                    >
                                        Delete
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">
                            Imports
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Track contact imports for this list.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-6 overflow-x-auto rounded-lg border border-border"
                >
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead
                            class="bg-muted/50 text-left tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-3">File</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Progress</th>
                                <th scope="col" class="px-4 py-3">Rows</th>
                                <th scope="col" class="px-4 py-3">Imported</th>
                                <th scope="col" class="px-4 py-3">Skipped</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="importItem in props.imports"
                                :key="importItem.id"
                                class="bg-card/50"
                            >
                                <td class="px-4 py-3">
                                    <div>
                                        <p class="font-medium text-foreground">
                                            {{ importItem.original_filename }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                formatDateTime(
                                                    importItem.created_at,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="
                                            getStatusConfig(importItem.status)
                                                .class
                                        "
                                    >
                                        {{
                                            getStatusConfig(importItem.status)
                                                .label
                                        }}
                                    </span>
                                    <p
                                        v-if="importItem.error_message"
                                        class="mt-1 text-xs text-red-600 dark:text-red-400"
                                    >
                                        {{ importItem.error_message }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-2 w-24 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                class="h-full transition-all duration-300"
                                                :class="
                                                    getStatusConfig(
                                                        importItem.status,
                                                    ).progressClass
                                                "
                                                :style="{
                                                    width: `${importItem.progress}%`,
                                                }"
                                            ></div>
                                        </div>
                                        <span
                                            class="text-sm font-medium text-foreground"
                                        >
                                            {{ importItem.progress }}%
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 font-mono text-muted-foreground tabular-nums"
                                >
                                    {{
                                        importItem.processed_count +
                                        importItem.skipped_count
                                    }}
                                    / {{ importItem.total_count }}
                                </td>
                                <td
                                    class="px-4 py-3 font-mono font-medium text-foreground tabular-nums"
                                >
                                    {{ importItem.processed_count }}
                                </td>
                                <td
                                    class="px-4 py-3 font-mono text-muted-foreground tabular-nums"
                                >
                                    {{ importItem.skipped_count }}
                                </td>
                            </tr>
                            <tr v-if="!hasImports">
                                <td
                                    colspan="6"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No imports yet. Import contacts to this list
                                    to see them here.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <TopicModal
            v-model:is-open="topicModalOpen"
            :lists="listOptions"
            :default-list-id="list.id"
            lock-list
        />
        <ConfirmDialog
            v-model:is-open="topicConfirmOpen"
            title="Delete topic?"
            :description="
                pendingTopicDelete
                    ? `This will permanently delete '${pendingTopicDelete.name}' and all subscription state for it.`
                    : ''
            "
            confirm-label="Delete topic"
            @confirm="confirmTopicDelete"
        />
    </AppLayout>
</template>
