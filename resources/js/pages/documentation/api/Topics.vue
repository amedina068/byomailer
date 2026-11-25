<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/lists/{listId}/topics',
        description: 'Get all topics for a list. Cursor-paginated (per_page default 25, max 100; cursor for next/previous page).',
        pathParams: [
            { name: 'listId', type: 'integer', required: true, description: 'The ID of the parent list.' },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The topic ID.' },
            { name: 'uuid', type: 'string', description: 'The topic UUID.' },
            { name: 'list_id', type: 'integer', description: 'The parent list ID.' },
            { name: 'name', type: 'string', description: 'Topic name. Unique within the list.' },
            { name: 'description', type: 'string|null', description: 'Optional description.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
            { name: 'meta', type: 'object', description: 'Cursor pagination metadata (path, per_page, next_cursor, prev_cursor).' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        uuid: 'topic-uuid-1',
                        list_id: 10,
                        name: 'Product Updates',
                        description: 'Release notes and feature announcements',
                        created_at: '2026-04-15T10:30:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/lists/10/topics',
                    per_page: 25,
                    next_cursor: null,
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/lists/{listId}/topics',
        description:
            'Create a topic on a list. All existing contacts on the list are auto-subscribed to the new topic asynchronously via a background job.',
        pathParams: [
            { name: 'listId', type: 'integer', required: true, description: 'The ID of the parent list.' },
        ],
        bodyFields: [
            { name: 'name', type: 'string', required: true, description: 'Human-readable topic name. Unique per list.' },
            { name: 'description', type: 'string', required: false, description: 'Optional topic description.' },
        ],
        requestExample: {
            name: 'Product Updates',
            description: 'Release notes and feature announcements',
        },
        response: {
            status: 201,
            body: {
                data: {
                    id: 2,
                    uuid: 'topic-uuid-2',
                    list_id: 10,
                    name: 'Product Updates',
                    description: 'Release notes and feature announcements',
                    created_at: '2026-04-15T10:35:00.000000Z',
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/lists/{listId}/topics/{topicId}',
        description: 'Update a topic.',
        pathParams: [
            { name: 'listId', type: 'integer', required: true, description: 'The ID of the parent list.' },
            { name: 'topicId', type: 'integer', required: true, description: 'The ID of the topic.' },
        ],
        bodyFields: [
            { name: 'name', type: 'string', required: true, description: 'Updated name.' },
            { name: 'description', type: 'string', required: false, description: 'Updated description.' },
        ],
        requestExample: {
            name: 'Product News',
            description: null,
        },
        response: {
            status: 200,
            body: {
                data: {
                    id: 2,
                    uuid: 'topic-uuid-2',
                    list_id: 10,
                    name: 'Product News',
                    description: null,
                    created_at: '2026-04-15T10:35:00.000000Z',
                },
            },
        },
    },
    {
        method: 'DELETE',
        path: '/api/v1/lists/{listId}/topics/{topicId}',
        description: 'Delete a topic and all subscription state for it.',
        pathParams: [
            { name: 'listId', type: 'integer', required: true, description: 'The ID of the parent list.' },
            { name: 'topicId', type: 'integer', required: true, description: 'The ID of the topic.' },
        ],
        response: {
            status: 204,
            body: {},
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-topics');
</script>

<template>
    <Head title="Topics API: Subscription Topics for Lists">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Topics API reference. List, create, update, and delete list topics, so subscribers can opt out of one content category and stay on your list."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Topics</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Topics let subscribers opt out of specific content categories while staying on your list.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`topics-${endpoint.method.toLowerCase()}`"
            />
        </div>
    </DocumentationLayout>
</template>
