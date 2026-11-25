<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import EndpointCard from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/tags',
        description:
            'Get all tags for the authenticated client. Cursor-paginated (per_page default 25, max 100; cursor for next/previous page).',
        responseFields: [
            { name: 'id', type: 'integer', description: 'The tag ID.' },
            { name: 'uuid', type: 'string', description: 'The tag UUID.' },
            { name: 'name', type: 'string', description: 'The tag name.' },
            {
                name: 'created_at',
                type: 'string',
                description: 'ISO 8601 creation timestamp.',
            },
            {
                name: 'meta',
                type: 'object',
                description:
                    'Cursor pagination metadata (path, per_page, next_cursor, prev_cursor).',
            },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        uuid: 'tag-uuid-1',
                        name: 'newsletter',
                        created_at: '2024-01-15T10:30:00.000000Z',
                    },
                    {
                        id: 2,
                        uuid: 'tag-uuid-2',
                        name: 'customer',
                        created_at: '2024-01-14T09:00:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/tags',
                    per_page: 25,
                    next_cursor: null,
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/tags',
        description: 'Create a new tag.',
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The name of the tag. Must be unique.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The tag ID.' },
            { name: 'uuid', type: 'string', description: 'The tag UUID.' },
            { name: 'name', type: 'string', description: 'The tag name.' },
            {
                name: 'created_at',
                type: 'string',
                description: 'ISO 8601 creation timestamp.',
            },
        ],
        requestExample: {
            name: 'premium',
        },
        response: {
            status: 201,
            body: {
                data: {
                    id: 3,
                    uuid: 'tag-uuid-3',
                    name: 'premium',
                    created_at: '2024-01-16T11:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/tags/{tag}',
        description: 'Rename a tag. When a saved filter of a newsletter that can still send, a sequence, a sequence step or a segment uses the tag, the request fails with 422.',
        pathParams: [
            {
                name: 'tag',
                type: 'integer',
                required: true,
                description: 'The ID of the tag to update.',
            },
        ],
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The new name for the tag. Must be unique.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The tag ID.' },
            { name: 'uuid', type: 'string', description: 'The tag UUID.' },
            {
                name: 'name',
                type: 'string',
                description: 'The updated tag name.',
            },
            {
                name: 'created_at',
                type: 'string',
                description: 'ISO 8601 creation timestamp.',
            },
        ],
        requestExample: {
            name: 'premium-user',
        },
        response: {
            status: 200,
            body: {
                data: {
                    id: 3,
                    uuid: 'tag-uuid-3',
                    name: 'premium-user',
                    created_at: '2024-01-16T11:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'DELETE',
        path: '/api/v1/tags/{tag}',
        description: 'Delete a tag and remove it from each contact. The removal runs the live tag automations. When a saved filter uses the tag, the request fails with 422.',
        pathParams: [
            {
                name: 'tag',
                type: 'integer',
                required: true,
                description: 'The ID of the tag to delete.',
            },
        ],
        response: {
            status: 204,
            body: {},
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-tags');
</script>

<template>
    <Head title="Tags API: Label and Segment Contacts">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Tags API reference. List, create, update, and delete the tags that you use to label contacts and build newsletter audiences."
        />
    </Head>

    <DocumentationLayout
        :active-section="activeSection"
        :sidebar-items="sidebarItems"
    >
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Tags</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Organize contacts with tags.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`tags-${endpoint.method.toLowerCase()}`"
            />
        </div>
    </DocumentationLayout>
</template>
