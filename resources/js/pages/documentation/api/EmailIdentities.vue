<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const identityFields = [
    { name: 'id', type: 'integer', description: 'The identity ID.' },
    { name: 'uuid', type: 'string', description: 'The identity UUID.' },
    { name: 'email', type: 'string', description: 'The verified sender email address.' },
    { name: 'name', type: 'string', description: 'The display name for this identity.' },
    { name: 'domain', type: 'string', description: 'The domain of the email address.' },
    { name: 'identity_status', type: 'string', description: 'Verification status: pending, verifying, verified, or failed.' },
    { name: 'verified_at', type: 'string|null', description: 'ISO 8601 timestamp when the identity was verified.' },
    { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
    { name: 'updated_at', type: 'string', description: 'ISO 8601 last update timestamp.' },
];

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/email-identities',
        description: 'Get all email identities for the authenticated client. Cursor-paginated (per_page default 25, max 100; cursor for next/previous page). Filter with ?identity_status=verified|pending|failed.',
        responseFields: [
            ...identityFields,
            { name: 'meta', type: 'object', description: 'Cursor pagination metadata (path, per_page, next_cursor, prev_cursor).' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        uuid: 'identity-uuid-1',
                        email: 'hello@example.com',
                        name: 'Acme Inc',
                        domain: 'example.com',
                        identity_status: 'verified',
                        verified_at: '2024-01-15T10:30:00.000000Z',
                        created_at: '2024-01-10T08:00:00.000000Z',
                        updated_at: '2024-01-15T10:30:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/email-identities',
                    per_page: 25,
                    next_cursor: null,
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/email-identities/{emailIdentityId}',
        description: 'Get a single email identity by ID.',
        pathParams: [
            { name: 'emailIdentityId', type: 'integer', required: true, description: 'The identity ID.' },
        ],
        responseFields: identityFields,
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    uuid: 'identity-uuid-1',
                    email: 'hello@example.com',
                    name: 'Acme Inc',
                    domain: 'example.com',
                    identity_status: 'verified',
                    verified_at: '2024-01-15T10:30:00.000000Z',
                    created_at: '2024-01-10T08:00:00.000000Z',
                    updated_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-email-identities');
</script>

<template>
    <Head title="Email Identities API: Verified Amazon SES Senders">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Email Identities API reference. List and read the verified Amazon SES sender identities that your account sends email from."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Email Identities</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                List verified sender identities used to send emails through Amazon SES.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`email-identities-${endpoint.method.toLowerCase()}`"
            />
        </div>
    </DocumentationLayout>
</template>
