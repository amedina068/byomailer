<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/email-logs',
        description: 'Get a cursor-paginated list of email logs for the authenticated account. Supports optional query parameters: status (sent, failed, delivered, bounced, complained, opened), operation (e.g., SendBulkEmail), recipient (partial match), per_page (default 25, max 100), and cursor (next/previous page token).',
        responseFields: [
            { name: 'id', type: 'integer', description: 'The email log ID.' },
            { name: 'provider', type: 'string', description: 'The email provider (e.g., ses).' },
            { name: 'operation', type: 'string', description: 'The SES API operation name.' },
            { name: 'recipient', type: 'string', description: 'The recipient email address (null for non-send operations).' },
            { name: 'message_id', type: 'string', description: 'The SES message ID (null for non-send operations).' },
            { name: 'status', type: 'string', description: 'The log status: sent, failed, delivered, bounced, complained, or opened.' },
            { name: 'error', type: 'string', description: 'Error message if the operation failed.' },
            { name: 'request_payload', type: 'object', description: 'The full SES API request payload (null for bulk per-recipient rows).' },
            { name: 'response_payload', type: 'object', description: 'The SES API response payload.' },
            { name: 'sns_payload', type: 'object', description: 'The SNS event payload from delivery notifications.' },
            { name: 'logged_at', type: 'string', description: 'ISO 8601 timestamp when the log was recorded.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        provider: 'ses',
                        operation: 'SendBulkEmail',
                        recipient: 'john@example.com',
                        message_id: '0102018e-abcd-1234-5678-abcdef123456',
                        status: 'sent',
                        error: null,
                        request_payload: null,
                        response_payload: { Status: 'SUCCESS', MessageId: '0102018e-abcd-1234-5678-abcdef123456' },
                        sns_payload: null,
                        logged_at: '2024-01-15T10:30:00.000000Z',
                        created_at: '2024-01-15T10:30:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/email-logs',
                    per_page: 25,
                    next_cursor: 'eyJpZCI6MTAxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/email-logs/{emailLogId}',
        description: 'Get a single email log entry by ID.',
        pathParams: [
            {
                name: 'emailLogId',
                type: 'integer',
                required: true,
                description: 'The ID of the email log to retrieve.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The email log ID.' },
            { name: 'provider', type: 'string', description: 'The email provider.' },
            { name: 'operation', type: 'string', description: 'The SES API operation name.' },
            { name: 'recipient', type: 'string', description: 'The recipient email address.' },
            { name: 'message_id', type: 'string', description: 'The SES message ID.' },
            { name: 'status', type: 'string', description: 'The log status.' },
            { name: 'error', type: 'string', description: 'Error message if the operation failed.' },
            { name: 'request_payload', type: 'object', description: 'The full SES API request payload.' },
            { name: 'response_payload', type: 'object', description: 'The SES API response payload.' },
            { name: 'sns_payload', type: 'object', description: 'The SNS event payload.' },
            { name: 'logged_at', type: 'string', description: 'ISO 8601 timestamp when the log was recorded.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    provider: 'ses',
                    operation: 'SendBulkEmail',
                    recipient: 'john@example.com',
                    message_id: '0102018e-abcd-1234-5678-abcdef123456',
                    status: 'delivered',
                    error: null,
                    request_payload: null,
                    response_payload: { Status: 'SUCCESS', MessageId: '0102018e-abcd-1234-5678-abcdef123456' },
                    sns_payload: { eventType: 'Delivery', mail: { messageId: '0102018e-abcd-1234-5678-abcdef123456' } },
                    logged_at: '2024-01-15T10:30:00.000000Z',
                    created_at: '2024-01-15T10:30:00.000000Z',
                },
            },
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-email-logs');
</script>

<template>
    <Head title="Email Logs API: Amazon SES Request Logs">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Email Logs API reference. List and read the Amazon SES API request and response logs to track email delivery status and debug sending problems."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Email Logs</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                View SES API request and response logs to track email delivery status and debug issues.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`email-logs-${endpoint.method.toLowerCase()}${endpoint.path.includes('{') ? '-single' : ''}`"
            />
        </div>
    </DocumentationLayout>
</template>
