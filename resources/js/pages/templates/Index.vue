<script setup lang="ts">
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import { PhArchive, PhArrowCounterClockwise, PhFileText, PhStar } from '@phosphor-icons/vue';
import { computed, ref } from 'vue';

import DuplicateEmailTemplateController from '@/actions/App/Http/Controllers/DuplicateEmailTemplateController';
import EmailTemplateArchiveController from '@/actions/App/Http/Controllers/EmailTemplateArchiveController';
import EmailTemplateController from '@/actions/App/Http/Controllers/EmailTemplateController';
import HtmlPreview from '@/components/templates/HtmlPreview.vue';
import { Button } from '@/components/ui/button';
import { toast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface EmailTemplate {
    id: number;
    name: string;
    html_content: string;
    is_default: boolean;
    is_system: boolean;
    archived_at: string | null;
    created_at: string;
    updated_at: string;
}

interface PaginatedTemplates {
    data: EmailTemplate[];
}

const props = defineProps<{
    templates: PaginatedTemplates;
    showingArchived: boolean;
    archivedCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Templates',
        href: EmailTemplateController.index().url,
    },
];

const formatDate = (value?: string | null): string => {
    return value ? new Date(value).toLocaleDateString() : '—';
};

const hasTemplates = computed(() => props.templates.data.length > 0);

const archiveTemplate = (template: EmailTemplate) => {
    router.post(
        EmailTemplateArchiveController.store(template.id).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                const message = (errors as Record<string, string>).template ?? 'Failed to archive template.';
                toast.error(message);
            },
        },
    );
};

const unarchiveTemplate = (template: EmailTemplate) => {
    router.delete(
        EmailTemplateArchiveController.destroy(template.id).url,
        { preserveScroll: true },
    );
};

const duplicateTemplate = (template: EmailTemplate) => {
    router.post(DuplicateEmailTemplateController(template.id).url);
};

const setDefault = (template: EmailTemplate) => {
    if (template.is_default) {
        return;
    }

    router.patch(EmailTemplateController.setDefault(template.id).url, {}, {
        preserveScroll: true,
    });
};

const toggleArchivedView = () => {
    router.visit(
        EmailTemplateController.index({ query: { archived: !props.showingArchived ? '1' : null } }).url,
        { preserveScroll: true },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="props.showingArchived ? 'Archived templates' : 'Templates'" />

        <section class="space-y-6">
            <div
                class="rounded-md border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h2 class="text-xl font-semibold text-foreground">
                            Email Templates
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Reusable shells with an editable content slot. Mark
                            one as default to pre-fill new newsletters and
                            sequence steps.
                        </p>
                    </div>
                    <Button v-if="!props.showingArchived" as-child size="sm">
                        <Link :href="EmailTemplateController.create().url">
                            New template
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                class="rounded-md border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-foreground">
                            {{ props.showingArchived ? 'Archived templates' : 'My Templates' }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{
                                props.showingArchived
                                    ? 'Restore to make a template available again.'
                                    : "Your client's templates. Star one to make it the default."
                            }}
                        </p>
                    </div>
                    <button
                        v-if="props.showingArchived || props.archivedCount > 0"
                        type="button"
                        class="text-sm font-medium text-primary transition hover:underline"
                        @click="toggleArchivedView"
                    >
                        {{
                            props.showingArchived
                                ? 'Back to active'
                                : `View archived (${props.archivedCount})`
                        }}
                    </button>
                </div>

                <div v-if="hasTemplates" class="mt-6">
                    <InfiniteScroll data="templates">
                        <div
                            class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <div
                                v-for="template in props.templates.data"
                                :key="template.id"
                                class="group relative overflow-hidden rounded-md border border-border"
                            >
                                <button
                                    v-if="!props.showingArchived"
                                    type="button"
                                    class="absolute right-2 top-2 z-10 rounded-[var(--bm-radius-md)] p-1.5 transition hover:bg-background/80"
                                    :title="template.is_default ? 'Default template' : 'Set as default'"
                                    @click="setDefault(template)"
                                >
                                    <PhStar
                                        class="h-5 w-5"
                                        :class="template.is_default ? 'text-yellow-500' : 'text-muted-foreground'"
                                        :weight="template.is_default ? 'fill' : 'regular'"
                                    />
                                </button>
                                <div class="aspect-video overflow-hidden bg-muted">
                                    <HtmlPreview :html="template.html_content" />
                                </div>
                                <div class="p-3">
                                    <h3
                                        class="truncate text-sm font-medium text-foreground"
                                    >
                                        {{ template.name }}
                                    </h3>
                                    <p class="mt-0.5 font-mono text-xs text-muted-foreground">
                                        Updated {{ formatDate(template.updated_at) }}
                                    </p>
                                    <div class="mt-3 flex items-center gap-3">
                                        <Link
                                            v-if="!props.showingArchived"
                                            class="text-sm font-medium text-primary transition hover:underline"
                                            :href="EmailTemplateController.edit(template.id).url"
                                        >
                                            Edit
                                        </Link>
                                        <button
                                            v-if="!props.showingArchived"
                                            class="text-sm font-medium text-muted-foreground transition hover:underline"
                                            @click="duplicateTemplate(template)"
                                        >
                                            Duplicate
                                        </button>
                                        <button
                                            v-if="!props.showingArchived && !template.is_default"
                                            class="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition hover:underline"
                                            @click="archiveTemplate(template)"
                                        >
                                            <PhArchive weight="regular" class="h-4 w-4" />
                                            Archive
                                        </button>
                                        <button
                                            v-if="props.showingArchived"
                                            class="inline-flex items-center gap-1 text-sm font-medium text-primary transition hover:underline"
                                            @click="unarchiveTemplate(template)"
                                        >
                                            <PhArrowCounterClockwise weight="regular" class="h-4 w-4" />
                                            Restore
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </InfiniteScroll>
                </div>

                <div
                    v-else
                    class="mt-6 flex flex-col items-center justify-center gap-3 rounded-md border border-dashed border-border py-12 text-center"
                >
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-full bg-muted"
                    >
                        <PhFileText class="h-6 w-6 text-muted-foreground" weight="regular" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            {{ props.showingArchived ? 'No archived templates' : 'No templates yet' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{
                                props.showingArchived
                                    ? 'Templates you archive will appear here.'
                                    : 'Create your first template to get started.'
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
