<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/projects',
        description:
            'Get all projects for the authenticated client. Cursor-paginated (per_page default 25, max 100; cursor for next/previous page). Each project includes counts of attached newsletters, sequences, and automations.',
        responseFields: [
            { name: 'id', type: 'integer', description: 'The project ID.' },
            { name: 'uuid', type: 'string', description: 'The project UUID.' },
            { name: 'name', type: 'string', description: 'The project name.' },
            { name: 'newsletters_count', type: 'integer', description: 'Number of newsletters assigned to the project.' },
            { name: 'sequences_count', type: 'integer', description: 'Number of sequences assigned to the project.' },
            { name: 'automations_count', type: 'integer', description: 'Number of automations assigned to the project.' },
            { name: 'visual_automations_count', type: 'integer', description: 'Number of visual automations assigned to the project.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
            { name: 'meta', type: 'object', description: 'Cursor pagination metadata (path, per_page, next_cursor, prev_cursor).' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 2,
                        uuid: 'project-uuid-2',
                        name: 'Q3 Launch',
                        newsletters_count: 4,
                        sequences_count: 1,
                        automations_count: 0,
                        visual_automations_count: 2,
                        created_at: '2026-07-20T10:00:00.000000Z',
                    },
                    {
                        id: 1,
                        uuid: 'project-uuid-1',
                        name: 'Evergreen Onboarding',
                        newsletters_count: 0,
                        sequences_count: 3,
                        automations_count: 1,
                        visual_automations_count: 0,
                        created_at: '2026-07-18T09:00:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/projects',
                    per_page: 25,
                    next_cursor: null,
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/projects/{projectId}',
        description: 'Get a single project by ID, including attached resource counts.',
        pathParams: [
            {
                name: 'projectId',
                type: 'integer',
                required: true,
                description: 'The ID of the project to retrieve.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The project ID.' },
            { name: 'uuid', type: 'string', description: 'The project UUID.' },
            { name: 'name', type: 'string', description: 'The project name.' },
            { name: 'newsletters_count', type: 'integer', description: 'Number of newsletters assigned to the project.' },
            { name: 'sequences_count', type: 'integer', description: 'Number of sequences assigned to the project.' },
            { name: 'automations_count', type: 'integer', description: 'Number of automations assigned to the project.' },
            { name: 'visual_automations_count', type: 'integer', description: 'Number of visual automations assigned to the project.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 2,
                    uuid: 'project-uuid-2',
                    name: 'Q3 Launch',
                    newsletters_count: 4,
                    sequences_count: 1,
                    automations_count: 0,
                    visual_automations_count: 2,
                    created_at: '2026-07-20T10:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/projects',
        description: 'Create a new project. The name must be unique per account (case-insensitive).',
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The project name. Max 255 characters. Unique per account (case-insensitive).',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The project ID.' },
            { name: 'uuid', type: 'string', description: 'The project UUID.' },
            { name: 'name', type: 'string', description: 'The project name.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        requestExample: {
            name: 'Q3 Launch',
        },
        response: {
            status: 201,
            body: {
                data: {
                    id: 3,
                    uuid: 'project-uuid-3',
                    name: 'Q3 Launch',
                    created_at: '2026-07-23T11:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/projects/{projectId}',
        description: 'Rename a project. The name must be unique per account (case-insensitive).',
        pathParams: [
            {
                name: 'projectId',
                type: 'integer',
                required: true,
                description: 'The ID of the project to update.',
            },
        ],
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The new project name. Max 255 characters. Unique per account (case-insensitive).',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The project ID.' },
            { name: 'uuid', type: 'string', description: 'The project UUID.' },
            { name: 'name', type: 'string', description: 'The updated project name.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        requestExample: {
            name: 'Q3 Product Launch',
        },
        response: {
            status: 200,
            body: {
                data: {
                    id: 3,
                    uuid: 'project-uuid-3',
                    name: 'Q3 Product Launch',
                    created_at: '2026-07-23T11:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'DELETE',
        path: '/api/v1/projects/{projectId}',
        description: 'Delete a project. Attached newsletters, sequences, and automations are detached, not deleted.',
        pathParams: [
            {
                name: 'projectId',
                type: 'integer',
                required: true,
                description: 'The ID of the project to delete.',
            },
        ],
        response: {
            status: 204,
            body: {},
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-projects');
</script>

<template>
    <Head title="Projects API: Group Newsletters and Sequences">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Projects API reference. Create, update, and delete projects that group newsletters, sequences, and automations, and filter list endpoints by project."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Projects</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Organize newsletters, sequences, and automations into named projects. Assign resources via
                <code class="rounded bg-muted px-1.5 py-0.5 text-sm">project_ids</code> on newsletter create/update, then filter the newsletter and
                sequence list endpoints with <code class="rounded bg-muted px-1.5 py-0.5 text-sm">?project=&#123;projectId&#125;</code>.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`projects-${endpoint.method.toLowerCase()}${endpoint.path.includes('{') ? '-single' : ''}`"
            />
        </div>
    </DocumentationLayout>
</template>
