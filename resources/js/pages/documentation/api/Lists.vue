<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/lists',
        description: 'Get all mailing lists for the authenticated client. Cursor-paginated (per_page default 25, max 100; cursor for next/previous page).',
        responseFields: [
            { name: 'id', type: 'integer', description: 'The list ID.' },
            { name: 'uuid', type: 'string', description: 'The list UUID.' },
            { name: 'name', type: 'string', description: 'The list name.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
            { name: 'meta', type: 'object', description: 'Cursor pagination metadata (path, per_page, next_cursor, prev_cursor).' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        uuid: 'list-uuid-1',
                        name: 'Weekly Newsletter',
                        created_at: '2024-01-10T08:00:00.000000Z',
                    },
                    {
                        id: 2,
                        uuid: 'list-uuid-2',
                        name: 'Product Updates',
                        created_at: '2024-01-12T15:30:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/lists',
                    per_page: 25,
                    next_cursor: null,
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/lists',
        description: 'Create a new mailing list.',
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The name of the mailing list.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The list ID.' },
            { name: 'uuid', type: 'string', description: 'The list UUID.' },
            { name: 'name', type: 'string', description: 'The list name.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        requestExample: {
            name: 'Beta Testers',
        },
        response: {
            status: 201,
            body: {
                data: {
                    id: 3,
                    uuid: 'list-uuid-3',
                    name: 'Beta Testers',
                    created_at: '2024-01-16T12:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/lists/{listId}',
        description: 'Update an existing mailing list.',
        pathParams: [
            {
                name: 'listId',
                type: 'integer',
                required: true,
                description: 'The ID of the list to update.',
            },
        ],
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The new name for the mailing list.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The list ID.' },
            { name: 'uuid', type: 'string', description: 'The list UUID.' },
            { name: 'name', type: 'string', description: 'The updated list name.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        requestExample: {
            name: 'Early Access Users',
        },
        response: {
            status: 200,
            body: {
                data: {
                    id: 3,
                    uuid: 'list-uuid-3',
                    name: 'Early Access Users',
                    created_at: '2024-01-16T12:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'DELETE',
        path: '/api/v1/lists/{listId}',
        description: 'Delete a mailing list.',
        pathParams: [
            {
                name: 'listId',
                type: 'integer',
                required: true,
                description: 'The ID of the list to delete.',
            },
        ],
        response: {
            status: 204,
            body: {},
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-lists');
</script>

<template>
    <Head title="Lists API: Create and Manage Mailing Lists">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Lists API reference. List, create, update, and delete the mailing lists that hold your contacts, with request and response examples."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Lists</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Manage mailing lists.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`lists-${endpoint.method.toLowerCase()}`"
            />
        </div>
    </DocumentationLayout>
</template>
