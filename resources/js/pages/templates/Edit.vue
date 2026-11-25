<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import EmailTemplateController from '@/actions/App/Http/Controllers/EmailTemplateController';
import TemplateForm from '@/components/templates/TemplateForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface EmailTemplate {
    id: number;
    name: string;
    html_content: string;
    created_at: string;
    updated_at: string;
}

const props = defineProps<{
    template: EmailTemplate;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Templates',
        href: EmailTemplateController.index().url,
    },
    {
        title: `Edit: ${props.template.name}`,
        href: EmailTemplateController.edit(props.template.id).url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit template: ${template.name}`" />

        <section class="space-y-6">
            <div
                class="rounded-md border border-sidebar-border/60 bg-card p-6 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-foreground">
                            Edit template
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Update the content for "{{ template.name }}"
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="EmailTemplateController.index().url">
                            Back to templates
                        </Link>
                    </Button>
                </div>

                <div class="mt-6">
                    <TemplateForm :template="template" />
                </div>
            </div>
        </section>
    </AppLayout>
</template>
