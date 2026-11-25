<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const metricsExample = {
    total: 1180,
    sent: 1180,
    delivered: 1170,
    pending: 0,
    opened: 540,
    bounced: 10,
    unsubscribed: 6,
    complained: 0,
    delivery_rate: 99.2,
    open_rate: 45.8,
    unsubscribe_rate: 0.5,
};

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/sequences',
        description:
            'Get a cursor-paginated list of sequences for the authenticated account. Live sequences are returned first, then alphabetically by name. Supports optional query parameters: status (draft, live, paused. paused is the same as draft: a paused sequence has the status draft), search (partial name match), project (numeric project ID — only sequences assigned to that project), per_page (default 25, max 100), and cursor (next/previous page token).',
        responseFields: [
            { name: 'id', type: 'integer', description: 'The sequence ID.' },
            { name: 'name', type: 'string', description: 'The sequence name.' },
            { name: 'status', type: 'string', description: 'The sequence status: draft, live, or paused. paused is the same as draft: a paused sequence has the status draft.' },
            { name: 'steps_count', type: 'integer', description: 'Number of steps in the sequence.' },
            { name: 'contacts_count', type: 'integer', description: 'Number of enrolled contacts.' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    { id: 7, name: 'Onboarding', status: 'live', steps_count: 4, contacts_count: 318 },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/sequences',
                    per_page: 25,
                    next_cursor: 'eyJpZCI6N30',
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/sequences/{sequenceId}',
        description: 'Get a single sequence with the full content of every step (subject, preview text, html and text body, sending identity and template).',
        pathParams: [
            { name: 'sequenceId', type: 'integer', required: true, description: 'The ID of the sequence to retrieve.' },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The sequence ID.' },
            { name: 'name', type: 'string', description: 'The sequence name.' },
            { name: 'status', type: 'string', description: 'The sequence status.' },
            { name: 'contacts_count', type: 'integer', description: 'Number of enrolled contacts.' },
            { name: 'steps_count', type: 'integer', description: 'Number of steps.' },
            { name: 'steps', type: 'array', description: 'Steps in send order, each with id, delay_days, delay_type, status and message content.' },
            { name: 'content_variables', type: 'object', description: 'Default values for template variables.' },
            { name: 'filters', type: 'array', description: 'Audience filters applied to the sequence.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 7,
                    name: 'Onboarding',
                    status: 'live',
                    contacts_count: 318,
                    steps_count: 4,
                    steps: [
                        {
                            id: 31,
                            sequence_id: 7,
                            delay_days: 0,
                            delay_type: 'days',
                            status: 'live',
                            message: {
                                id: 88,
                                subject: 'Welcome aboard',
                                preview_text: 'Glad you are here',
                                html_content: '<p>Hi {{ name }}…</p>',
                                text_content: 'Hi {{ name }}…',
                                email_identity_id: 3,
                            },
                            created_at: '2026-06-01T09:00:00.000000Z',
                        },
                    ],
                    filters: [],
                    content_variables: { name: 'there' },
                    created_at: '2026-06-01T09:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/sequences/{sequenceId}/stats',
        description: 'Get sequence-level delivery and engagement stats: contact, unsubscribe and click totals plus aggregate mail metrics across all steps. For a per-step breakdown call the step stats endpoint.',
        pathParams: [
            { name: 'sequenceId', type: 'integer', required: true, description: 'The ID of the sequence to read stats for.' },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The sequence ID.' },
            { name: 'name', type: 'string', description: 'The sequence name.' },
            { name: 'status', type: 'string', description: 'The sequence status.' },
            { name: 'stats', type: 'object', description: 'Sequence-wide contacts, unsubscribed and clicked totals (distinct contacts).' },
            { name: 'mail_metrics', type: 'object', description: 'Aggregate metrics across every step: total, sent, delivered, pending, opened, bounced, unsubscribed, complained and delivery/open/unsubscribe rates.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 7,
                    name: 'Onboarding',
                    status: 'live',
                    stats: { contacts: 318, unsubscribed: 6, clicked: 121 },
                    mail_metrics: metricsExample,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}',
        description: 'Get the full content of a single sequence step.',
        pathParams: [
            { name: 'sequenceId', type: 'integer', required: true, description: 'The ID of the sequence the step belongs to.' },
            { name: 'sequenceStepId', type: 'integer', required: true, description: 'The ID of the step to retrieve.' },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The step ID.' },
            { name: 'sequence_id', type: 'integer', description: 'The parent sequence ID.' },
            { name: 'delay_days', type: 'integer', description: 'Delay before this step.' },
            { name: 'delay_type', type: 'string', description: 'Unit of the delay (e.g. days).' },
            { name: 'status', type: 'string', description: 'The step status.' },
            { name: 'filters', type: 'array', description: 'Step filters. A contact who does not match them skips only this step. Empty when the step has no filters.' },
            { name: 'message', type: 'object', description: 'Step message content: subject, preview_text, html_content, text_content, email identity and template.' },
            { name: 'created_at', type: 'string', description: 'ISO 8601 creation timestamp.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 31,
                    sequence_id: 7,
                    delay_days: 0,
                    delay_type: 'days',
                    status: 'live',
                    filters: [],
                    message: {
                        id: 88,
                        subject: 'Welcome aboard',
                        preview_text: 'Glad you are here',
                        html_content: '<p>Hi {{ name }}…</p>',
                        text_content: 'Hi {{ name }}…',
                        email_identity: { id: 3, name: 'Acme', email: 'hello@acme.com' },
                    },
                    created_at: '2026-06-01T09:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/sequences/{sequenceId}/variables',
        description:
            'Get the {{ variables }} that the steps of the sequence use, and the saved default of each one. A default fills a variable when the contact has no value for it. Reserved variables (content, unsubscribe_url, preferences_url, opt_in_url) are not included.',
        pathParams: [{ name: 'sequenceId', type: 'integer', required: true, description: 'The ID of the sequence.' }],
        responseFields: [
            { name: 'sequence_id', type: 'integer', description: 'The sequence ID.' },
            { name: 'detected_variables', type: 'array', description: 'The variable names that the step subjects and bodies use.' },
            { name: 'content_variables', type: 'object', description: 'Map of variable name to its saved default.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    sequence_id: 7,
                    detected_variables: ['checkout_url', 'first_name'],
                    content_variables: { checkout_url: 'https://example.com/checkout', first_name: '' },
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/sequences/{sequenceId}/variables',
        description:
            'Replace the content variable defaults of the sequence. The object replaces all saved defaults. Send an empty object to clear them. Each default is text of up to 255 characters, the same rule as the dashboard. A default can name a variable that no step uses yet. Works on draft and live sequences.',
        pathParams: [{ name: 'sequenceId', type: 'integer', required: true, description: 'The ID of the sequence.' }],
        bodyFields: [
            { name: 'content_variables', type: 'object', required: true, description: 'Map of variable name to default text (maximum 255 characters each). Send {} to clear.' },
        ],
        requestExample: {
            content_variables: { checkout_url: 'https://example.com/checkout', first_name: 'there' },
        },
        response: {
            status: 200,
            body: {
                data: {
                    sequence_id: 7,
                    detected_variables: ['checkout_url', 'first_name'],
                    content_variables: { checkout_url: 'https://example.com/checkout', first_name: 'there' },
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}/filters',
        description:
            'Replace the filters of a single sequence step. A contact who does not match the step filters skips only this email and continues to the next step. The sequence filters still apply first. Works on draft and live steps. Send an empty array to clear the filters. Uses the same filter validation as newsletters. Returns the updated step.',
        pathParams: [
            { name: 'sequenceId', type: 'integer', required: true, description: 'The ID of the sequence the step belongs to.' },
            { name: 'sequenceStepId', type: 'integer', required: true, description: 'The ID of the step to update.' },
        ],
        bodyFields: [
            { name: 'filters', type: 'array', required: true, description: 'Up to 20 filters. See the Filters page for the structure. Send [] to clear.' },
        ],
        requestExample: {
            filters: [{ connector: 'and', category: 'tags', mode: 'any', values: ['vip'] }],
        },
        response: {
            status: 200,
            body: {
                data: {
                    id: 31,
                    sequence_id: 7,
                    delay_days: 2,
                    delay_type: 'days',
                    status: 'live',
                    filters: [{ connector: 'and', category: 'tags', mode: 'any', values: ['vip'] }],
                    message: { id: 88, subject: 'Welcome aboard' },
                    created_at: '2026-06-01T09:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}/stats',
        description: 'Get delivery and engagement stats for a single sequence step. Contacts are counted in the furthest step they have reached, so per-step totals do not double-count contacts who have progressed.',
        pathParams: [
            { name: 'sequenceId', type: 'integer', required: true, description: 'The ID of the sequence the step belongs to.' },
            { name: 'sequenceStepId', type: 'integer', required: true, description: 'The ID of the step to read stats for.' },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The step ID.' },
            { name: 'sequence_id', type: 'integer', description: 'The parent sequence ID.' },
            { name: 'subject', type: 'string', description: 'The step message subject.' },
            { name: 'delay_days', type: 'integer', description: 'Delay before this step.' },
            { name: 'delay_type', type: 'string', description: 'Unit of the delay (e.g. days).' },
            { name: 'status', type: 'string', description: 'The step status.' },
            { name: 'stats', type: 'object', description: 'Contacts reached, unsubscribed and clicked totals (distinct contacts) for this step.' },
            { name: 'mail_metrics', type: 'object', description: 'Step metrics: total, sent, delivered, pending, opened, bounced, unsubscribed, complained and delivery/open/unsubscribe rates.' },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 31,
                    sequence_id: 7,
                    subject: 'Welcome aboard',
                    delay_days: 0,
                    delay_type: 'days',
                    status: 'live',
                    stats: { contacts: 318, unsubscribed: 2, clicked: 80 },
                    mail_metrics: metricsExample,
                },
            },
        },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-sequences');
</script>

<template>
    <Head title="Sequences API: Drip Sequences and Step Stats">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Sequences API reference. List and read automated email sequences, read step content, set step filters and content variables, and get sequence and step stats."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Sequences</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Read your automated email sequences — list them, read a sequence and its steps' content, set the filters of a step, set the content variable defaults, and pull delivery and engagement stats at both the sequence and individual-step level.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`sequences-${index}`"
            />
        </div>
    </DocumentationLayout>
</template>
