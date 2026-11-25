<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import CodeBlock from '@/components/documentation/CodeBlock.vue';
import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const signatureExample = `// Node.js example
const crypto = require('crypto');
const expected = 'sha256=' + crypto
  .createHmac('sha256', secret)
  .update(timestamp + '.' + rawBody)
  .digest('hex');
const ok = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signatureHeader));`;

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/webhook-endpoints',
        description:
            'List all webhook endpoints for the authenticated client. Admin-only. Cursor-paginated. The signing secret is never returned by this endpoint.',
        responseFields: [
            { name: 'id', type: 'integer', description: 'Endpoint ID.' },
            { name: 'uuid', type: 'string', description: 'Endpoint UUID.' },
            { name: 'url', type: 'string', description: 'Destination HTTPS URL.' },
            { name: 'events', type: 'array', description: 'Subscribed event types.' },
            { name: 'active', type: 'boolean', description: 'Whether the endpoint receives deliveries.' },
            { name: 'disabled_reason', type: 'string|null', description: 'manual or auto_failures when inactive.' },
            { name: 'consecutive_failures', type: 'integer', description: 'Failed deliveries since the last success.' },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        uuid: 'wh-uuid-1',
                        url: 'https://example.com/webhooks/byomailer',
                        description: 'CRM sync',
                        events: ['contact.subscribed', 'newsletter.sent'],
                        active: true,
                        disabled_reason: null,
                        consecutive_failures: 0,
                        last_triggered_at: '2026-01-15T10:30:00+00:00',
                    },
                ],
                meta: { path: 'https://mail.example.com/api/v1/webhook-endpoints', per_page: 25, next_cursor: null, prev_cursor: null },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/webhook-endpoints',
        description:
            'Create a webhook endpoint. The plaintext signing secret is returned ONCE in this response — store it to verify the X-Byomailer-Signature header. URL must be a public HTTPS endpoint. Supported events: contact.subscribed, contact.unsubscribed, contact.resubscribed, newsletter.sent, sequence_step.sent, sequence.completed.',
        requestExample: {
            url: 'https://example.com/webhooks/byomailer',
            events: ['contact.subscribed', 'newsletter.sent'],
            description: 'CRM sync',
        },
        response: {
            status: 201,
            body: {
                data: {
                    id: 1,
                    uuid: 'wh-uuid-1',
                    url: 'https://example.com/webhooks/byomailer',
                    events: ['contact.subscribed', 'newsletter.sent'],
                    active: true,
                    secret: 'whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/webhook-endpoints/{id}',
        description: 'Update an endpoint. Only active and description are mutable — URL and events are immutable, recreate the endpoint to change them.',
        requestExample: { active: false, description: 'Paused for maintenance' },
        response: { status: 200, body: { data: { id: 1, active: false } } },
    },
    {
        method: 'DELETE',
        path: '/api/v1/webhook-endpoints/{id}',
        description: 'Delete an endpoint and stop all deliveries to it.',
        response: { status: 204, body: {} },
    },
    {
        method: 'GET',
        path: '/api/v1/webhook-endpoints/{id}/deliveries',
        description: 'Cursor-paginated delivery log for an endpoint.',
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 10,
                        uuid: 'del-uuid-10',
                        event_type: 'contact.subscribed',
                        status: 'success',
                        attempts: 1,
                        response_status: 200,
                        delivered_at: '2026-01-15T10:30:01+00:00',
                    },
                ],
                meta: { per_page: 25, next_cursor: null, prev_cursor: null },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/webhook-endpoints/{id}/test',
        description: 'Queue a sample test delivery to the endpoint. Rate-limited per client.',
        response: { status: 202, body: { data: { id: 11, event_type: 'webhook.test', status: 'pending' } } },
    },
    {
        method: 'POST',
        path: '/api/v1/webhook-endpoints/{id}/secret',
        description:
            'Rotate the signing secret. The new plaintext secret is returned ONCE; existing receivers fail signature verification until updated.',
        response: { status: 200, body: { data: { id: 1, secret: 'whsec_yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy' } } },
    },
];

const { activeSection, sidebarItems } = useDocumentationSidebar('api-webhooks');
</script>

<template>
    <Head title="Webhooks API: Signed Event Callbacks">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Webhooks API reference. Register HTTPS endpoints for signed POST callbacks, list deliveries, send a test event, and rotate the signing secret."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Webhooks</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Register HTTPS endpoints that receive signed POST callbacks when events happen in your account.
            </p>
        </div>

        <div class="mb-10 rounded-lg border p-6">
            <h2 class="mb-6 text-2xl font-semibold">Verifying signatures</h2>
            <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                <div class="min-w-0 lg:sticky lg:top-32">
                    <p class="text-muted-foreground">
                        Every delivery carries an <code>X-Byomailer-Signature</code> header of the form
                        <code>sha256=&lt;hex&gt;</code>, plus <code>X-Byomailer-Timestamp</code> (Unix seconds),
                        <code>X-Byomailer-Event</code>, and <code>X-Byomailer-Delivery</code> (a unique id). Compute the
                        expected signature as <code>HMAC-SHA256(timestamp + "." + rawBody, secret)</code> using your
                        endpoint's signing secret, and compare it with a timing-safe comparison. Reject deliveries whose
                        timestamp is outside a ±5 minute window to prevent replay.
                    </p>
                </div>
                <div class="min-w-0 lg:sticky lg:top-32">
                    <CodeBlock title="Signature verification" :code="signatureExample" lang="javascript" />
                </div>
            </div>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :id="`webhooks-${endpoint.method.toLowerCase()}-${index}`"
                :key="index"
                :endpoint="endpoint"
            />
        </div>
    </DocumentationLayout>
</template>
