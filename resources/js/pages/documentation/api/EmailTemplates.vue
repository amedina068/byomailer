<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const templateFields = [
    { name: 'id', type: 'integer', description: 'The template ID.' },
    { name: 'name', type: 'string', description: 'The template display name.' },
    { name: 'html_content', type: 'string', description: 'The HTML wrapper. Newsletter html_content is rendered into the {{ content }} slot.' },
    { name: 'is_default', type: 'boolean', description: 'True when this template is the account default.' },
    { name: 'is_system', type: 'boolean', description: 'True when this is a system-provided template (no client_id).' },
    { name: 'archived_at', type: 'string|null', description: 'ISO 8601 timestamp when archived, or null.' },
    { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
    { name: 'updated_at', type: 'string', description: 'ISO 8601 last update timestamp.' },
];

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/email-templates',
        description:
            'List email templates for the authenticated client. Returns active templates by default; pass ?archived=true for archived templates.',
        responseFields: templateFields,
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        name: 'Default wrapper',
                        html_content: '<table>...{{ content }}...</table>',
                        is_default: true,
                        is_system: false,
                        archived_at: null,
                        created_at: '2024-02-01T08:00:00.000000Z',
                        updated_at: '2024-02-01T08:00:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/email-templates',
                    per_page: 25,
                    next_cursor: null,
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/email-templates/default',
        description:
            'Get the active default email template for the authenticated client. Returns {"data": null} when no default is set.',
        responseFields: templateFields,
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    name: 'Default wrapper',
                    html_content: '<table>...{{ content }}...</table>',
                    is_default: true,
                    is_system: false,
                    archived_at: null,
                    created_at: '2024-02-01T08:00:00.000000Z',
                    updated_at: '2024-02-01T08:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/email-templates/{emailTemplateId}',
        description: 'Get a single email template by ID.',
        pathParams: [
            { name: 'emailTemplateId', type: 'integer', required: true, description: 'The template ID.' },
        ],
        responseFields: templateFields,
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    name: 'Default wrapper',
                    html_content: '<table>...{{ content }}...</table>',
                    is_default: true,
                    is_system: false,
                    archived_at: null,
                    created_at: '2024-02-01T08:00:00.000000Z',
                    updated_at: '2024-02-01T08:00:00.000000Z',
                },
            },
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-email-templates');
</script>

<template>
    <Head title="Email Templates API: Newsletter Templates">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Email Templates API reference. List templates, read one template or the default template, and render newsletter html_content into the template content slot."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Email Templates</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Browse the email templates available for newsletters. Newsletter <code>html_content</code> is rendered
                into the template's <code v-pre>{{ content }}</code> slot when an
                <code>email_template_id</code> is provided.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`email-templates-${endpoint.method.toLowerCase()}-${endpoint.path.includes('default') ? 'default' : endpoint.path.includes('{') ? 'single' : 'list'}`"
            />
        </div>
    </DocumentationLayout>
</template>
