<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { PhFilePlus } from '@phosphor-icons/vue';

import EmailTemplateController from '@/actions/App/Http/Controllers/EmailTemplateController';
import HtmlPreview from '@/components/templates/HtmlPreview.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface EmailTemplate {
    id: number;
    name: string;
    html_content: string;
}

const props = defineProps<{
    systemTemplates: EmailTemplate[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Templates',
        href: EmailTemplateController.index().url,
    },
    {
        title: 'New template',
        href: EmailTemplateController.create().url,
    },
];

const startFromStarter = (template: EmailTemplate) => {
    router.post(EmailTemplateController.startFromStarter(template.id).url);
};

const startBlank = () => {
    router.post(EmailTemplateController.startBlank().url);
};

const contentPlaceholder = '{{ content }}';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="New template" />

        <section class="space-y-6">
            <div
                class="rounded-md border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Pick a starting point
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Every template needs a
                            <code class="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{{ contentPlaceholder }}</code>
                            placeholder marking where newsletter or step content
                            lives. Pick a starter or start from scratch — we'll
                            open the editor next.
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="EmailTemplateController.index().url">
                            Back to templates
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <button
                    type="button"
                    class="group overflow-hidden rounded-md border border-dashed border-border bg-card text-left transition hover:border-primary"
                    @click="startBlank"
                >
                    <div class="flex aspect-video items-center justify-center bg-muted">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-background transition group-hover:bg-primary/10">
                            <PhFilePlus class="h-6 w-6 text-muted-foreground group-hover:text-primary" weight="regular" />
                        </div>
                    </div>
                    <div class="p-3">
                        <h3 class="truncate text-sm font-medium text-foreground">
                            Start from scratch
                        </h3>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Empty template with a content slot and unsubscribe footer.
                        </p>
                    </div>
                </button>

                <button
                    v-for="template in props.systemTemplates"
                    :key="template.id"
                    type="button"
                    class="group overflow-hidden rounded-md border border-border bg-card text-left transition hover:border-primary"
                    @click="startFromStarter(template)"
                >
                    <div class="aspect-video overflow-hidden bg-muted">
                        <HtmlPreview :html="template.html_content" />
                    </div>
                    <div class="p-3">
                        <h3 class="truncate text-sm font-medium text-foreground">
                            {{ template.name }}
                        </h3>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Click to start editing a copy.
                        </p>
                    </div>
                </button>
            </div>
        </section>
    </AppLayout>
</template>
