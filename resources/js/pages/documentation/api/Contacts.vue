<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/contacts',
        description:
            'List all contacts for the authenticated client. Cursor-paginated. Supports optional query parameters: lists[] (array of list IDs), tags[] (array of tag IDs), topics[] (array of topic IDs — matches contacts currently subscribed), search (partial match on name/email), include_unsubscribed (bool, default false), per_page (default 25, max 100), and cursor (next/previous page token).',
        responseFields: [
            { name: 'id', type: 'integer', description: 'The contact ID.' },
            { name: 'uuid', type: 'string', description: 'The contact UUID.' },
            { name: 'name', type: 'string', description: "The contact's full name." },
            { name: 'email', type: 'string', description: "The contact's email address." },
            { name: 'extra_attributes', type: 'object', description: 'Custom attributes stored on the contact.' },
            { name: 'unsubscribed_at', type: 'string|null', description: 'Timestamp when the contact unsubscribed, or null.' },
            { name: 'unsubscribed_by_contact', type: 'boolean', description: 'Whether the contact unsubscribed themselves.' },
            { name: 'tags', type: 'array', description: 'Tags assigned to the contact.' },
            { name: 'lists', type: 'array', description: 'Lists the contact belongs to.' },
            { name: 'topics', type: 'array', description: 'Topics the contact is subscribed to.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                        name: 'John Doe',
                        email: 'john@example.com',
                        extra_attributes: { company: 'Acme Inc' },
                        unsubscribed_at: null,
                        unsubscribed_by_contact: false,
                        tags: [
                            { id: 1, uuid: 'tag-uuid-1', name: 'newsletter', created_at: '2024-01-15T10:30:00.000000Z' },
                        ],
                        lists: [
                            { id: 1, uuid: 'list-uuid-1', name: 'Weekly Newsletter', created_at: '2024-01-10T08:00:00.000000Z' },
                        ],
                        topics: [
                            { id: 1, uuid: 'topic-uuid-1', list_id: 1, name: 'Product Updates', description: null, created_at: '2024-01-15T10:30:00.000000Z' },
                        ],
                        created_at: '2024-01-15T10:30:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/contacts',
                    per_page: 25,
                    next_cursor: 'eyJpZCI6MTAxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/contacts/{contactId}',
        description: 'Retrieve a single contact by ID, including tags, lists, and topics.',
        pathParams: [
            {
                name: 'contactId',
                type: 'integer',
                required: true,
                description: 'The ID of the contact to retrieve.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The contact ID.' },
            { name: 'uuid', type: 'string', description: 'The contact UUID.' },
            { name: 'name', type: 'string', description: "The contact's full name." },
            { name: 'email', type: 'string', description: "The contact's email address." },
            { name: 'extra_attributes', type: 'object', description: 'Custom attributes stored on the contact.' },
            { name: 'unsubscribed_at', type: 'string|null', description: 'Timestamp when the contact unsubscribed, or null.' },
            { name: 'unsubscribed_by_contact', type: 'boolean', description: 'Whether the contact unsubscribed themselves.' },
            { name: 'tags', type: 'array', description: 'Tags assigned to the contact.' },
            { name: 'lists', type: 'array', description: 'Lists the contact belongs to.' },
            { name: 'topics', type: 'array', description: 'Topics the contact is subscribed to.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                    name: 'John Doe',
                    email: 'john@example.com',
                    extra_attributes: { company: 'Acme Inc', phone: '+1234567890' },
                    unsubscribed_at: null,
                    unsubscribed_by_contact: false,
                    tags: [
                        { id: 1, uuid: 'tag-uuid-1', name: 'newsletter', created_at: '2024-01-15T10:30:00.000000Z' },
                    ],
                    lists: [
                        { id: 1, uuid: 'list-uuid-1', name: 'Weekly Newsletter', created_at: '2024-01-10T08:00:00.000000Z' },
                    ],
                    topics: [
                        { id: 1, uuid: 'topic-uuid-1', list_id: 1, name: 'Product Updates', description: null, created_at: '2024-01-15T10:30:00.000000Z' },
                    ],
                    created_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/lists/{listId}/contacts',
        description:
            'Create a new contact or update an existing one and assign them to the specified list. The subscription never changes: an unsubscribed contact stays unsubscribed.',
        pathParams: [
            {
                name: 'listId',
                type: 'integer',
                required: true,
                description: 'The ID of the list to add the contact to.',
            },
        ],
        bodyFields: [
            {
                name: 'email',
                type: 'string',
                required: true,
                description: 'The email address of the contact.',
            },
            {
                name: 'name',
                type: 'string',
                required: false,
                description: 'The full name of the contact.',
            },
            {
                name: 'tags',
                type: 'string[]',
                required: false,
                description: 'An array of tag names to assign to the contact. Tags are created automatically if they don\'t exist.',
            },
            {
                name: 'remove_tags',
                type: 'string[]',
                required: false,
                description: 'An array of tag names to remove from the contact. A name that has no tag, or a tag that the contact does not have, is ignored. A name in tags and in remove_tags returns 422.',
            },
            {
                name: '*',
                type: 'string',
                required: false,
                description: 'Any additional fields are stored as extra attributes on the contact (e.g. company, phone).',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The contact ID.' },
            { name: 'uuid', type: 'string', description: 'The contact UUID.' },
            { name: 'name', type: 'string', description: 'The contact\'s full name.' },
            { name: 'email', type: 'string', description: 'The contact\'s email address.' },
            { name: 'extra_attributes', type: 'object', description: 'Custom attributes stored on the contact.' },
            { name: 'unsubscribed_at', type: 'string|null', description: 'Timestamp when the contact unsubscribed, or null.' },
            { name: 'tags', type: 'array', description: 'Tags assigned to the contact.' },
            { name: 'lists', type: 'array', description: 'Lists the contact belongs to.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        requestExample: {
            email: 'john@example.com',
            name: 'John Doe',
            tags: ['newsletter', 'customer'],
            company: 'Acme Inc',
            phone: '+1234567890',
        },
        response: {
            status: 201,
            body: {
                data: {
                    id: 1,
                    uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                    name: 'John Doe',
                    email: 'john@example.com',
                    extra_attributes: {
                        company: 'Acme Inc',
                        phone: '+1234567890',
                    },
                    unsubscribed_at: null,
                    unsubscribed_by_contact: false,
                    tags: [
                        {
                            id: 1,
                            uuid: 'tag-uuid-1',
                            name: 'newsletter',
                            created_at: '2024-01-15T10:30:00.000000Z',
                        },
                    ],
                    lists: [
                        {
                            id: 1,
                            uuid: 'list-uuid-1',
                            name: 'Weekly Newsletter',
                            created_at: '2024-01-10T08:00:00.000000Z',
                        },
                    ],
                    created_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
    {
        method: 'PUT',
        path: '/api/v1/contacts/{contactId}',
        description: 'Update an existing contact by ID.',
        pathParams: [
            {
                name: 'contactId',
                type: 'integer',
                required: true,
                description: 'The ID of the contact to update.',
            },
        ],
        bodyFields: [
            {
                name: 'name',
                type: 'string',
                required: false,
                description: 'The full name of the contact.',
            },
            {
                name: 'tags',
                type: 'string[]',
                required: false,
                description: 'An array of tag names to add to the contact. Tags are created automatically if they don\'t exist. The contact keeps its other tags.',
            },
            {
                name: 'remove_tags',
                type: 'string[]',
                required: false,
                description: 'An array of tag names to remove from the contact. A name that has no tag, or a tag that the contact does not have, is ignored. A name in tags and in remove_tags returns 422.',
            },
            {
                name: '*',
                type: 'string',
                required: false,
                description: 'Any additional fields are stored as extra attributes on the contact.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The contact ID.' },
            { name: 'uuid', type: 'string', description: 'The contact UUID.' },
            { name: 'name', type: 'string', description: 'The updated contact name.' },
            { name: 'email', type: 'string', description: 'The contact\'s email address.' },
            { name: 'extra_attributes', type: 'object', description: 'Custom attributes stored on the contact.' },
            { name: 'tags', type: 'array', description: 'Updated tags assigned to the contact.' },
            { name: 'lists', type: 'array', description: 'Lists the contact belongs to.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        requestExample: {
            name: 'John Smith',
            tags: ['vip'],
            remove_tags: ['trial'],
        },
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                    name: 'John Smith',
                    email: 'john@example.com',
                    extra_attributes: {
                        company: 'Acme Inc',
                        phone: '+1234567890',
                    },
                    unsubscribed_at: null,
                    unsubscribed_by_contact: false,
                    tags: [
                        {
                            id: 3,
                            uuid: 'tag-uuid-3',
                            name: 'vip',
                            created_at: '2024-01-15T10:30:00.000000Z',
                        },
                    ],
                    lists: [
                        {
                            id: 1,
                            uuid: 'list-uuid-1',
                            name: 'Weekly Newsletter',
                            created_at: '2024-01-10T08:00:00.000000Z',
                        },
                    ],
                    created_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/contacts/{contactId}/unsubscribe',
        description:
            'Unsubscribe a contact. The contact is marked as unsubscribed and will no longer receive emails.',
        pathParams: [
            {
                name: 'contactId',
                type: 'integer',
                required: true,
                description: 'The ID of the contact to unsubscribe.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The contact ID.' },
            { name: 'uuid', type: 'string', description: 'The contact UUID.' },
            { name: 'name', type: 'string', description: 'The contact\'s full name.' },
            { name: 'email', type: 'string', description: 'The contact\'s email address.' },
            { name: 'extra_attributes', type: 'object', description: 'Custom attributes stored on the contact.' },
            { name: 'unsubscribed_at', type: 'string', description: 'Timestamp when the contact was unsubscribed.' },
            { name: 'unsubscribed_by_contact', type: 'boolean', description: 'Whether the contact unsubscribed themselves.' },
            { name: 'tags', type: 'array', description: 'Tags assigned to the contact.' },
            { name: 'lists', type: 'array', description: 'Lists the contact belongs to.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                    name: 'John Doe',
                    email: 'john@example.com',
                    extra_attributes: {},
                    unsubscribed_at: '2024-01-16T14:00:00.000000Z',
                    unsubscribed_by_contact: false,
                    tags: [],
                    lists: [
                        {
                            id: 1,
                            uuid: 'list-uuid-1',
                            name: 'Weekly Newsletter',
                            created_at: '2024-01-10T08:00:00.000000Z',
                        },
                    ],
                    created_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/contacts/{contactId}/subscribe',
        description:
            'Subscribe a contact again. The request clears an unsubscribe that an admin or the API made. A contact that unsubscribed through an email link gets 422. A complaint suppression and a global suppression get 422. A bounce suppression of your client gets 422 unless remove_suppression is true. An update of the contact never changes the subscription.',
        pathParams: [
            {
                name: 'contactId',
                type: 'integer',
                required: true,
                description: 'The ID of the contact to subscribe again.',
            },
        ],
        bodyFields: [
            {
                name: 'remove_suppression',
                type: 'boolean',
                required: false,
                description: 'Remove the bounce suppression of the address. Without it, a suppressed address gets 422.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The contact ID.' },
            { name: 'unsubscribed_at', type: 'string|null', description: 'Null after the request.' },
            { name: 'unsubscribed_by_contact', type: 'boolean', description: 'Whether the contact unsubscribed themselves.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                    name: 'John Doe',
                    email: 'john@example.com',
                    extra_attributes: {},
                    unsubscribed_at: null,
                    unsubscribed_by_contact: false,
                    tags: [],
                    lists: [],
                    created_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
    {
        method: 'DELETE',
        path: '/api/v1/lists/{listId}/contacts/{contactId}',
        description:
            'Remove a contact from a specific list. The contact is not deleted, only disassociated from the list.',
        pathParams: [
            {
                name: 'listId',
                type: 'integer',
                required: true,
                description: 'The ID of the list to remove the contact from.',
            },
            {
                name: 'contactId',
                type: 'integer',
                required: true,
                description: 'The ID of the contact to remove.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The contact ID.' },
            { name: 'uuid', type: 'string', description: 'The contact UUID.' },
            { name: 'name', type: 'string', description: 'The contact\'s full name.' },
            { name: 'email', type: 'string', description: 'The contact\'s email address.' },
            { name: 'tags', type: 'array', description: 'Tags assigned to the contact (empty after removal).' },
            { name: 'lists', type: 'array', description: 'Remaining lists (empty if removed from all).' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                    name: 'John Doe',
                    email: 'john@example.com',
                    extra_attributes: {},
                    unsubscribed_at: null,
                    unsubscribed_by_contact: false,
                    tags: [],
                    lists: [],
                    created_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-contacts');
</script>

<template>
    <Head title="Contacts API: Add, Update, and Unsubscribe Contacts">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Contacts API reference. List and read contacts, add a contact to a list, update a contact, unsubscribe a contact, and remove a contact from a list."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Contacts</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Manage your email contacts within lists.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`contacts-${index}`"
            />
        </div>
    </DocumentationLayout>
</template>
