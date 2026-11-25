<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/blocks',
        description: 'Get all blocks for the authenticated client. Cursor-paginated (per_page default 25, max 100; cursor for next/previous page).',
        responseFields: [
            { name: 'id', type: 'integer', description: 'The block ID.' },
            { name: 'uuid', type: 'string', description: 'The block UUID.' },
            { name: 'name', type: 'string', description: 'The block name (lowercase with underscores).' },
            { name: 'html_content', type: 'string', description: 'The HTML content of the block.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
            { name: 'updated_at', type: 'string', description: 'ISO 8601 last update timestamp.' },
            { name: 'meta', type: 'object', description: 'Cursor pagination metadata (path, per_page, next_cursor, prev_cursor).' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        uuid: 'block-uuid-1',
                        name: 'footer_links',
                        html_content: '<div><a href="https://example.com">Home</a></div>',
                        created_at: '2024-01-15T10:30:00.000000Z',
                        updated_at: '2024-01-15T10:30:00.000000Z',
                    },
                    {
                        id: 2,
                        uuid: 'block-uuid-2',
                        name: 'welcome_header',
                        html_content: '<h1>Welcome!</h1>',
                        created_at: '2024-01-14T09:00:00.000000Z',
                        updated_at: '2024-01-14T09:00:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/blocks',
                    per_page: 25,
                    next_cursor: null,
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/blocks/{blockId}',
        description: 'Get a single block by ID.',
        pathParams: [
            {
                name: 'blockId',
                type: 'integer',
                required: true,
                description: 'The ID of the block to retrieve.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The block ID.' },
            { name: 'uuid', type: 'string', description: 'The block UUID.' },
            { name: 'name', type: 'string', description: 'The block name.' },
            { name: 'html_content', type: 'string', description: 'The HTML content of the block.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
            { name: 'updated_at', type: 'string', description: 'ISO 8601 last update timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    uuid: 'block-uuid-1',
                    name: 'footer_links',
                    html_content: '<div><a href="https://example.com">Home</a></div>',
                    created_at: '2024-01-15T10:30:00.000000Z',
                    updated_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/blocks',
        description: 'Create a new reusable HTML content block. The html_content is validated for safe HTML — script tags, iframes, event handlers, and other unsafe elements are rejected.',
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The block name. Must be lowercase letters, numbers, and underscores only. Unique per account.',
            },
            {
                name: 'html_content',
                type: 'string',
                required: true,
                description: 'The HTML content of the block. Must be valid, safe HTML.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The block ID.' },
            { name: 'uuid', type: 'string', description: 'The block UUID.' },
            { name: 'name', type: 'string', description: 'The block name.' },
            { name: 'html_content', type: 'string', description: 'The HTML content of the block.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
            { name: 'updated_at', type: 'string', description: 'ISO 8601 last update timestamp.' },
        ],
        requestExample: {
            name: 'footer_links',
            html_content: '<div><a href="https://example.com">Home</a></div>',
        },
        response: {
            status: 201,
            body: {
                data: {
                    id: 3,
                    uuid: 'block-uuid-3',
                    name: 'footer_links',
                    html_content: '<div><a href="https://example.com">Home</a></div>',
                    created_at: '2024-01-16T11:00:00.000000Z',
                    updated_at: '2024-01-16T11:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/blocks/{blockId}',
        description: 'Update an existing block. The html_content is validated for safe HTML.',
        pathParams: [
            {
                name: 'blockId',
                type: 'integer',
                required: true,
                description: 'The ID of the block to update.',
            },
        ],
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The new block name. Must be lowercase letters, numbers, and underscores only. Unique per account.',
            },
            {
                name: 'html_content',
                type: 'string',
                required: true,
                description: 'The new HTML content. Must be valid, safe HTML.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The block ID.' },
            { name: 'uuid', type: 'string', description: 'The block UUID.' },
            { name: 'name', type: 'string', description: 'The updated block name.' },
            { name: 'html_content', type: 'string', description: 'The updated HTML content.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
            { name: 'updated_at', type: 'string', description: 'ISO 8601 last update timestamp.' },
        ],
        requestExample: {
            name: 'updated_footer',
            html_content: '<div><a href="https://example.com">Updated Home</a></div>',
        },
        response: {
            status: 200,
            body: {
                data: {
                    id: 3,
                    uuid: 'block-uuid-3',
                    name: 'updated_footer',
                    html_content: '<div><a href="https://example.com">Updated Home</a></div>',
                    created_at: '2024-01-16T11:00:00.000000Z',
                    updated_at: '2024-01-16T12:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'DELETE',
        path: '/api/v1/blocks/{blockId}',
        description: 'Delete a block.',
        pathParams: [
            {
                name: 'blockId',
                type: 'integer',
                required: true,
                description: 'The ID of the block to delete.',
            },
        ],
        response: {
            status: 204,
            body: {},
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-blocks');
</script>

<template>
    <Head title="Blocks API: Reusable Email Content Blocks">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Blocks API reference. List, read, create, update, and delete the reusable HTML content blocks that you embed in your emails."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Blocks</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Manage reusable HTML content blocks that can be embedded in emails using the
                <code class="rounded bg-muted px-1.5 py-0.5 text-sm" v-text="'{{ block.name }}'" /> template syntax.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`blocks-${endpoint.method.toLowerCase()}${endpoint.path.includes('{') ? '-single' : ''}`"
            />
        </div>
    </DocumentationLayout>
</template>
