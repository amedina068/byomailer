<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Field } from '@/components/documentation/FieldList.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const filterChildFields: Field[] = [
    {
        name: 'connector',
        type: 'string',
        description: '"and" or "or". Defaults to "and".',
    },
    {
        name: 'category',
        type: 'string',
        required: true,
        description: '"tags", "lists", "topics", or "attributes".',
    },
    {
        name: 'mode',
        type: 'string',
        required: true,
        description: '"all", "any", or "none".',
    },
    {
        name: 'values',
        type: 'array',
        required: true,
        description:
            'Strings for tags, integers for lists/topics, or {key, value} objects for attributes.',
    },
];

const endpoints: Endpoint[] = [
    {
        method: 'GET',
        path: '/api/v1/newsletters',
        description:
            'Get all newsletters for the authenticated account. Supports optional query parameters: status (draft, scheduled, queued, ab_testing, sent, failed), search (title or subject match), project (numeric project ID — only newsletters assigned to that project), per_page, and cursor.',
        responseFields: [
            { name: 'id', type: 'integer', description: 'The newsletter ID.' },
            {
                name: 'uuid',
                type: 'string',
                description: 'The newsletter UUID.',
            },
            {
                name: 'title',
                type: 'string',
                description: 'The newsletter title.',
            },
            {
                name: 'status',
                type: 'string',
                description:
                    'One of draft, scheduled, queued, sent, failed, ab_testing.',
            },
            {
                name: 'subject',
                type: 'string',
                description: 'The email subject line.',
            },
            {
                name: 'preview_text',
                type: 'string',
                description:
                    'Optional inbox preheader shown next to the subject in clients like Gmail and Apple Mail.',
            },
            {
                name: 'email_identity_id',
                type: 'integer',
                description:
                    'The ID of the email identity used to send, or null.',
            },
            {
                name: 'from_email',
                type: 'string',
                description:
                    'The sender email address, populated once the identity is attached.',
            },
            {
                name: 'from_name',
                type: 'string',
                description: 'The sender display name.',
            },
            {
                name: 'html_content',
                type: 'string',
                description: 'The HTML content of the newsletter.',
            },
            {
                name: 'text_content',
                type: 'string',
                description: 'The plain text content of the newsletter.',
            },
            {
                name: 'scheduled_at',
                type: 'string',
                description: 'ISO 8601 scheduled send timestamp, or null.',
            },
            {
                name: 'sent_at',
                type: 'string',
                description: 'ISO 8601 sent timestamp, or null.',
            },
            {
                name: 'filters',
                type: 'array',
                description:
                    'Contact filters applied to the newsletter. See Filters page for structure.',
            },
            {
                name: 'ab_test_enabled',
                type: 'boolean',
                description:
                    'True when the newsletter has an A/B variant configured.',
            },
            {
                name: 'total_contacts_reached',
                type: 'integer',
                description: 'Total recipients captured at dispatch time.',
            },
            {
                name: 'metrics',
                type: 'object',
                description:
                    'Delivery + engagement counters derived from contact_mails.',
                children: [
                    {
                        name: 'total',
                        type: 'integer',
                        description: 'Total recipients.',
                    },
                    {
                        name: 'sent',
                        type: 'integer',
                        description: 'Successfully sent.',
                    },
                    {
                        name: 'pending',
                        type: 'integer',
                        description: 'Not yet sent.',
                    },
                    {
                        name: 'opened',
                        type: 'integer',
                        description: 'Opens tracked.',
                    },
                    {
                        name: 'bounced',
                        type: 'integer',
                        description: 'Bounces tracked.',
                    },
                    {
                        name: 'unsubscribed',
                        type: 'integer',
                        description: 'Unsubscribes tracked.',
                    },
                    {
                        name: 'complained',
                        type: 'integer',
                        description: 'Complaints tracked.',
                    },
                    {
                        name: 'open_rate',
                        type: 'number',
                        description: 'Percentage (nullable when sent = 0).',
                    },
                    {
                        name: 'unsubscribe_rate',
                        type: 'number',
                        description: 'Percentage (nullable when sent = 0).',
                    },
                ],
            },
            {
                name: 'created_at',
                type: 'string',
                description: 'ISO 8601 creation timestamp.',
            },
            {
                name: 'updated_at',
                type: 'string',
                description: 'ISO 8601 last update timestamp.',
            },
            {
                name: 'meta',
                type: 'object',
                description:
                    'Cursor pagination metadata (path, per_page, next_cursor, prev_cursor). next_cursor is null at the end. The list endpoint omits html_content and text_content — fetch the show endpoint for the full body.',
            },
        ],
        response: {
            status: 200,
            body: {
                data: [
                    {
                        id: 1,
                        uuid: 'newsletter-uuid-1',
                        title: 'March Product Update',
                        status: 'sent',
                        subject: "What's new in March",
                        email_identity_id: 1,
                        from_email: 'hello@example.com',
                        from_name: 'Acme Inc',
                        scheduled_at: '2024-03-01T09:00:00.000000Z',
                        sent_at: '2024-03-01T09:00:00.000000Z',
                        filters: [
                            {
                                connector: 'and',
                                category: 'tags',
                                mode: 'any',
                                values: ['vip'],
                            },
                        ],
                        ab_test_enabled: false,
                        total_contacts_reached: 842,
                        metrics: {
                            total: 842,
                            sent: 842,
                            pending: 0,
                            opened: 301,
                            bounced: 4,
                            unsubscribed: 3,
                            complained: 0,
                            open_rate: 35.7,
                            unsubscribe_rate: 0.4,
                        },
                        created_at: '2024-02-28T10:00:00.000000Z',
                        updated_at: '2024-03-01T09:00:00.000000Z',
                    },
                    {
                        id: 2,
                        uuid: 'newsletter-uuid-2',
                        title: 'April Newsletter',
                        status: 'scheduled',
                        subject: 'April highlights',
                        email_identity_id: 1,
                        from_email: 'hello@example.com',
                        from_name: 'Acme Inc',
                        scheduled_at: '2024-04-05T12:00:00.000000Z',
                        sent_at: null,
                        filters: [],
                        ab_test_enabled: false,
                        total_contacts_reached: 0,
                        metrics: {
                            total: 0,
                            sent: 0,
                            pending: 0,
                            opened: 0,
                            bounced: 0,
                            unsubscribed: 0,
                            complained: 0,
                            open_rate: 0,
                            unsubscribe_rate: 0,
                        },
                        created_at: '2024-03-20T14:00:00.000000Z',
                        updated_at: '2024-03-20T14:00:00.000000Z',
                    },
                ],
                meta: {
                    path: 'https://mail.example.com/api/v1/newsletters',
                    per_page: 25,
                    next_cursor:
                        'eyJpZCI6MTAxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
                    prev_cursor: null,
                },
            },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/newsletters/{newsletterId}',
        description: 'Get a single newsletter by ID.',
        pathParams: [
            {
                name: 'newsletterId',
                type: 'integer',
                required: true,
                description: 'The ID of the newsletter to retrieve.',
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The newsletter ID.' },
            {
                name: 'uuid',
                type: 'string',
                description: 'The newsletter UUID.',
            },
            {
                name: 'title',
                type: 'string',
                description: 'The newsletter title.',
            },
            {
                name: 'status',
                type: 'string',
                description:
                    'One of draft, scheduled, queued, sent, failed, ab_testing.',
            },
            {
                name: 'subject',
                type: 'string',
                description: 'The email subject line.',
            },
            {
                name: 'preview_text',
                type: 'string',
                description:
                    'Optional inbox preheader shown next to the subject in clients like Gmail and Apple Mail.',
            },
            {
                name: 'email_identity_id',
                type: 'integer',
                description:
                    'The ID of the email identity used to send, or null.',
            },
            {
                name: 'from_email',
                type: 'string',
                description:
                    'The sender email address, populated once the identity is attached.',
            },
            {
                name: 'from_name',
                type: 'string',
                description: 'The sender display name.',
            },
            {
                name: 'html_content',
                type: 'string',
                description: 'The HTML content of the newsletter.',
            },
            {
                name: 'text_content',
                type: 'string',
                description: 'The plain text content of the newsletter.',
            },
            {
                name: 'scheduled_at',
                type: 'string',
                description: 'ISO 8601 scheduled send timestamp, or null.',
            },
            {
                name: 'sent_at',
                type: 'string',
                description: 'ISO 8601 sent timestamp, or null.',
            },
            {
                name: 'filters',
                type: 'array',
                description:
                    'Contact filters applied to the newsletter. See Filters page for structure.',
            },
            {
                name: 'ab_test_enabled',
                type: 'boolean',
                description:
                    'True when the newsletter has an A/B variant configured.',
            },
            {
                name: 'total_contacts_reached',
                type: 'integer',
                description: 'Total recipients captured at dispatch time.',
            },
            {
                name: 'metrics',
                type: 'object',
                description:
                    'Delivery + engagement counters derived from contact_mails.',
            },
            {
                name: 'created_at',
                type: 'string',
                description: 'ISO 8601 creation timestamp.',
            },
            {
                name: 'updated_at',
                type: 'string',
                description: 'ISO 8601 last update timestamp.',
            },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    id: 1,
                    uuid: 'newsletter-uuid-1',
                    title: 'March Product Update',
                    status: 'sent',
                    subject: "What's new in March",
                    email_identity_id: 1,
                    from_email: 'hello@example.com',
                    from_name: 'Acme Inc',
                    html_content:
                        '<h1>March Update</h1><p>Here is what is new...</p>',
                    text_content: 'March Update\n\nHere is what is new...',
                    scheduled_at: '2024-03-01T09:00:00.000000Z',
                    sent_at: '2024-03-01T09:00:00.000000Z',
                    filters: [
                        {
                            connector: 'and',
                            category: 'tags',
                            mode: 'any',
                            values: ['vip'],
                        },
                    ],
                    ab_test_enabled: false,
                    total_contacts_reached: 842,
                    metrics: {
                        total: 842,
                        sent: 842,
                        pending: 0,
                        opened: 301,
                        bounced: 4,
                        unsubscribed: 3,
                        complained: 0,
                        open_rate: 35.7,
                        unsubscribe_rate: 0.4,
                    },
                    created_at: '2024-02-28T10:00:00.000000Z',
                    updated_at: '2024-03-01T09:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/newsletters',
        description:
            'Create a new newsletter. Defaults to "draft" status. Set status to "live" to send (requires subject, html_content, scheduled_at, and a verified email_identity_id). When scheduled_at is in the future the newsletter is stored as "scheduled" and dispatched by the scheduler when due; otherwise it is dispatched immediately with status "queued". scheduled_at is required when status is "live" and prohibited otherwise. It must be an ISO 8601 datetime in UTC and accepts values up to 60 seconds in the past (clock skew tolerance). If title is omitted, it defaults to "Untitled newsletter".',
        bodyFields: [
            {
                name: 'title',
                type: 'string',
                description:
                    'The newsletter title. Max 255 characters. Defaults to "Untitled newsletter" if omitted.',
            },
            {
                name: 'subject',
                type: 'string',
                description:
                    'The email subject line. Max 255 characters. Supports Liquid. Required when status is "live".',
            },
            {
                name: 'preview_text',
                type: 'string',
                description:
                    'Optional inbox preheader shown next to the subject in Gmail and Apple Mail. Max 255 characters. Supports Liquid, for example {{ name }}.',
            },
            {
                name: 'html_content',
                type: 'string',
                description:
                    'Valid HTML content of the newsletter. Automatically converted to plain text. Supports Liquid: {{ variable }} placeholders, filters, and tags such as {% if %} and {% for %}. Content that is not valid Liquid returns 422. Required when status is "live".',
            },
            {
                name: 'status',
                type: 'string',
                description: 'Either "draft" or "live". Defaults to "draft".',
            },
            {
                name: 'scheduled_at',
                type: 'string',
                description:
                    'ISO 8601 datetime in UTC. Required when status is "live"; prohibited otherwise. Must be at-or-after the current time (60s clock-skew tolerance). To send immediately, pass the current UTC ISO 8601 timestamp (e.g. new Date().toISOString()).',
            },
            {
                name: 'email_identity_id',
                type: 'integer',
                description:
                    'The ID of the email identity to send from. Must belong to your account and be verified. Required when status is "live".',
            },
            {
                name: 'email_template_id',
                type: 'integer',
                description:
                    'Optional ID of an email_template belonging to your account. Wraps your html_content inside the template body slot.',
            },
            {
                name: 'ab_test_enabled',
                type: 'boolean',
                description:
                    'When true, subject_b and html_content_b are required. The system stores both variants and the dispatcher splits the audience for the test phase.',
            },
            {
                name: 'subject_b',
                type: 'string',
                description:
                    'Subject for the B variant. Required when ab_test_enabled is true.',
            },
            {
                name: 'preview_text_b',
                type: 'string',
                description:
                    'Optional inbox preheader for the B variant. Max 255 characters.',
            },
            {
                name: 'html_content_b',
                type: 'string',
                description:
                    'HTML body for the B variant. Required when ab_test_enabled is true.',
            },
            {
                name: 'content_variables',
                type: 'object',
                description:
                    'Map of {{ variable }} defaults rendered into the email subject and body. Example: { "first_name": "Friend" }.',
            },
            {
                name: 'filters',
                type: 'array',
                description:
                    'Contact filters to target specific recipients. See Filters page for structure.',
                children: filterChildFields,
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The newsletter ID.' },
            {
                name: 'uuid',
                type: 'string',
                description: 'The newsletter UUID.',
            },
            {
                name: 'title',
                type: 'string',
                description: 'The newsletter title.',
            },
            {
                name: 'status',
                type: 'string',
                description: 'Effective status: draft, scheduled, or queued.',
            },
            {
                name: 'subject',
                type: 'string',
                description: 'The email subject line.',
            },
            {
                name: 'preview_text',
                type: 'string',
                description: 'Optional inbox preheader, or null.',
            },
            {
                name: 'email_identity_id',
                type: 'integer',
                description:
                    'The ID of the email identity used to send, or null.',
            },
            {
                name: 'email_identity',
                type: 'object',
                description:
                    'Embedded email identity object (id, email, name, domain, identity_status).',
            },
            {
                name: 'email_template_id',
                type: 'integer',
                description: 'The template ID, or null.',
            },
            {
                name: 'from_email',
                type: 'string',
                description: 'Populated once identity is attached.',
            },
            {
                name: 'from_name',
                type: 'string',
                description: 'Populated once identity is attached.',
            },
            {
                name: 'html_content',
                type: 'string',
                description: 'The HTML content of the newsletter.',
            },
            {
                name: 'text_content',
                type: 'string',
                description: 'The auto-generated plain text content.',
            },
            {
                name: 'content_variables',
                type: 'object',
                description: 'Defaults map for {{ variable }} placeholders.',
            },
            {
                name: 'ab_test_enabled',
                type: 'boolean',
                description: 'True when an A/B variant was configured.',
            },
            {
                name: 'subject_b',
                type: 'string',
                description: 'B-variant subject, or null.',
            },
            {
                name: 'preview_text_b',
                type: 'string',
                description: 'B-variant preheader, or null.',
            },
            {
                name: 'html_content_b',
                type: 'string',
                description: 'B-variant HTML body, or null.',
            },
            {
                name: 'text_content_b',
                type: 'string',
                description: 'B-variant plain text, or null.',
            },
            {
                name: 'ab_test_winner_variant',
                type: 'string',
                description:
                    '"a" or "b" once the winner has been picked, otherwise null.',
            },
            {
                name: 'filters',
                type: 'array',
                description: 'Contact filters applied to the newsletter.',
            },
            {
                name: 'total_contacts_reached',
                type: 'integer',
                description: 'Starts at 0 until dispatch completes.',
            },
            {
                name: 'metrics',
                type: 'object',
                description:
                    'Delivery + engagement counters. open_rate and unsubscribe_rate default to 0 when no sends.',
            },
            {
                name: 'created_at',
                type: 'string',
                description: 'ISO 8601 creation timestamp.',
            },
            {
                name: 'updated_at',
                type: 'string',
                description: 'ISO 8601 last update timestamp.',
            },
        ],
        requestExample: {
            title: 'March Product Update',
            subject: "What's new in March",
            html_content: '<h1>March Update</h1><p>Here is what is new...</p>',
            status: 'draft',
            filters: [
                {
                    connector: 'and',
                    category: 'tags',
                    mode: 'any',
                    values: ['vip', 'premium'],
                },
            ],
        },
        response: {
            status: 201,
            body: {
                data: {
                    id: 3,
                    uuid: 'newsletter-uuid-3',
                    title: 'March Product Update',
                    status: 'draft',
                    subject: "What's new in March",
                    email_identity_id: null,
                    from_email: null,
                    from_name: null,
                    html_content:
                        '<h1>March Update</h1><p>Here is what is new...</p>',
                    text_content: 'March Update\n\nHere is what is new...',
                    scheduled_at: null,
                    sent_at: null,
                    filters: [
                        {
                            connector: 'and',
                            category: 'tags',
                            mode: 'any',
                            values: ['vip', 'premium'],
                        },
                    ],
                    ab_test_enabled: false,
                    total_contacts_reached: 0,
                    metrics: {
                        total: 0,
                        sent: 0,
                        pending: 0,
                        opened: 0,
                        bounced: 0,
                        unsubscribed: 0,
                        complained: 0,
                        open_rate: null,
                        unsubscribe_rate: null,
                    },
                    created_at: '2024-03-25T11:00:00.000000Z',
                    updated_at: '2024-03-25T11:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/newsletters/{newsletterId}',
        description:
            'Update an existing draft or scheduled newsletter. Queued, ab_testing, sent, or failed newsletters return 422 with a status validation error. A request with only title and project_ids changes these labels in any status. All fields are optional but at least one must be provided. When setting status to "live", the newsletter must have subject, html_content, and a verified email_identity_id (from this request or already on record). scheduled_at is rejected unless status is "live". Disabling A/B (ab_test_enabled=false) deletes the variant-B message.',
        pathParams: [
            {
                name: 'newsletterId',
                type: 'integer',
                required: true,
                description: 'The ID of the newsletter to update.',
            },
        ],
        bodyFields: [
            {
                name: 'title',
                type: 'string',
                description: 'The new newsletter title. Max 255 characters.',
            },
            {
                name: 'subject',
                type: 'string',
                description:
                    'The new email subject line. Max 255 characters. Supports Liquid. Must exist (in request or on record) when setting status to "live".',
            },
            {
                name: 'preview_text',
                type: 'string',
                description:
                    'Optional inbox preheader. Max 255 characters. Supports Liquid, for example {{ name }}.',
            },
            {
                name: 'html_content',
                type: 'string',
                description:
                    'Valid HTML content. Supports Liquid. Content that is not valid Liquid returns 422. Must exist (in request or on record) when setting status to "live".',
            },
            {
                name: 'status',
                type: 'string',
                description:
                    'Either "draft" or "live". Use "live" to send the newsletter immediately.',
            },
            {
                name: 'scheduled_at',
                type: 'string',
                description:
                    'ISO 8601 datetime in UTC. Required when promoting status to "live"; rejected otherwise.',
            },
            {
                name: 'email_identity_id',
                type: 'integer',
                description:
                    'The ID of the email identity to send from. Must belong to your account. Must exist (in request or on record) when setting status to "live".',
            },
            {
                name: 'filters',
                type: 'array',
                description:
                    'Contact filters to target specific recipients. See Filters page for structure.',
                children: filterChildFields,
            },
        ],
        responseFields: [
            { name: 'id', type: 'integer', description: 'The newsletter ID.' },
            {
                name: 'uuid',
                type: 'string',
                description: 'The newsletter UUID.',
            },
            {
                name: 'title',
                type: 'string',
                description: 'The updated newsletter title.',
            },
            {
                name: 'status',
                type: 'string',
                description: 'Effective status after update.',
            },
            {
                name: 'subject',
                type: 'string',
                description: 'The updated email subject line.',
            },
            {
                name: 'preview_text',
                type: 'string',
                description: 'Optional inbox preheader, or null.',
            },
            {
                name: 'email_identity_id',
                type: 'integer',
                description:
                    'The ID of the email identity used to send, or null.',
            },
            {
                name: 'from_email',
                type: 'string',
                description: 'Populated once identity is attached.',
            },
            {
                name: 'from_name',
                type: 'string',
                description: 'Populated once identity is attached.',
            },
            {
                name: 'html_content',
                type: 'string',
                description: 'The updated HTML content.',
            },
            {
                name: 'text_content',
                type: 'string',
                description: 'The auto-generated plain text content.',
            },
            {
                name: 'filters',
                type: 'array',
                description: 'Contact filters applied to the newsletter.',
            },
            {
                name: 'ab_test_enabled',
                type: 'boolean',
                description: 'A/B flag mirrored from the record.',
            },
            {
                name: 'total_contacts_reached',
                type: 'integer',
                description: 'Recipients locked in at dispatch time.',
            },
            {
                name: 'metrics',
                type: 'object',
                description: 'Delivery + engagement counters.',
            },
            {
                name: 'created_at',
                type: 'string',
                description: 'ISO 8601 creation timestamp.',
            },
            {
                name: 'updated_at',
                type: 'string',
                description: 'ISO 8601 last update timestamp.',
            },
        ],
        requestExample: {
            title: 'Updated March Product Update',
            subject: "Updated: What's new in March",
            html_content: '<h1>March Update</h1><p>Updated content...</p>',
            status: 'draft',
            filters: [
                {
                    connector: 'and',
                    category: 'lists',
                    mode: 'all',
                    values: [1, 5],
                },
            ],
        },
        response: {
            status: 200,
            body: {
                data: {
                    id: 3,
                    uuid: 'newsletter-uuid-3',
                    title: 'Updated March Product Update',
                    status: 'draft',
                    subject: "Updated: What's new in March",
                    email_identity_id: 1,
                    from_email: 'hello@example.com',
                    from_name: 'Acme Inc',
                    html_content:
                        '<h1>March Update</h1><p>Updated content...</p>',
                    text_content: 'March Update\n\nUpdated content...',
                    scheduled_at: null,
                    sent_at: null,
                    filters: [
                        {
                            connector: 'and',
                            category: 'lists',
                            mode: 'all',
                            values: [1, 5],
                        },
                    ],
                    ab_test_enabled: false,
                    total_contacts_reached: 0,
                    metrics: {
                        total: 0,
                        sent: 0,
                        pending: 0,
                        opened: 0,
                        bounced: 0,
                        unsubscribed: 0,
                        complained: 0,
                        open_rate: null,
                        unsubscribe_rate: null,
                    },
                    created_at: '2024-03-25T11:00:00.000000Z',
                    updated_at: '2024-03-25T12:00:00.000000Z',
                },
            },
        },
    },
    {
        method: 'DELETE',
        path: '/api/v1/newsletters/{newsletterId}',
        description:
            'Delete a draft or scheduled newsletter. Queued, ab_testing, sent, or failed newsletters return 422 with a status validation error.',
        pathParams: [
            {
                name: 'newsletterId',
                type: 'integer',
                required: true,
                description: 'The ID of the newsletter to delete.',
            },
        ],
        response: {
            status: 204,
            body: {},
        },
    },
    {
        method: 'POST',
        path: '/api/v1/newsletters/{newsletterId}/test-mail',
        description:
            "Send a test version of the newsletter to the authenticated user's email. If the newsletter has no sender, the request returns 422 with an email_identity_id validation error. If SES does not accept the email, the request returns 422 with a send validation error.",
        pathParams: [
            {
                name: 'newsletterId',
                type: 'integer',
                required: true,
                description: 'The newsletter to send a test of.',
            },
        ],
        response: {
            status: 200,
            body: {
                message: 'Test email sent to you@example.com',
                sent_to: 'you@example.com',
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/newsletters/{newsletterId}/ab-winner',
        description:
            'Pick the winning A/B variant. Only available while the newsletter status is "ab_testing" — other statuses return 422. Marks the newsletter "sent" and stores ab_test_winner_variant.',
        pathParams: [
            {
                name: 'newsletterId',
                type: 'integer',
                required: true,
                description: 'The newsletter ID.',
            },
        ],
        bodyFields: [
            {
                name: 'winner_variant',
                type: 'string',
                required: true,
                description: '"a" or "b".',
            },
        ],
        requestExample: { winner_variant: 'a' },
        response: {
            status: 200,
            body: {
                data: {
                    id: 12,
                    status: 'sent',
                    ab_test_enabled: true,
                    ab_test_winner_variant: 'a',
                },
            },
        },
    },
    {
        method: 'POST',
        path: '/api/v1/newsletters/contacts/preview',
        description:
            'Returns the number of contacts that would receive a newsletter with the given filters. Uses the same filter validation as create/update.',
        bodyFields: [
            {
                name: 'filters',
                type: 'array',
                description:
                    'Filters to evaluate. See Filters page for structure.',
                children: filterChildFields,
            },
        ],
        requestExample: {
            filters: [
                {
                    connector: 'and',
                    category: 'tags',
                    mode: 'any',
                    values: ['vip'],
                },
            ],
        },
        response: {
            status: 200,
            body: { count: 1284 },
        },
    },
    {
        method: 'GET',
        path: '/api/v1/newsletters/{newsletterId}/variables',
        description:
            "Returns the list of {{ variables }} detected in the newsletter's subject and HTML body, plus the current content_variables defaults map. Reserved variables (content, unsubscribe_url, preferences_url, opt_in_url) are excluded automatically.",
        pathParams: [
            {
                name: 'newsletterId',
                type: 'integer',
                required: true,
                description: 'The newsletter ID.',
            },
        ],
        responseFields: [
            {
                name: 'newsletter_id',
                type: 'integer',
                description: 'The newsletter ID.',
            },
            {
                name: 'detected_variables',
                type: 'array',
                description: 'Sorted list of detected variable names.',
            },
            {
                name: 'content_variables',
                type: 'object',
                description: 'Map of variable => default value.',
            },
        ],
        response: {
            status: 200,
            body: {
                data: {
                    newsletter_id: 12,
                    detected_variables: ['company', 'first_name'],
                    content_variables: { company: '', first_name: 'Friend' },
                },
            },
        },
    },
    {
        method: 'PATCH',
        path: '/api/v1/newsletters/{newsletterId}/variables',
        description:
            'Replace the content variable defaults of a draft or scheduled newsletter. The object replaces all saved defaults. Send an empty object to clear them. Each default is text of up to 255 characters, the same rule as the dashboard. A default can name a variable that the content does not use yet. Each variable that the content uses and the object leaves out gets an empty default. A newsletter that is sending or sent cannot be changed.',
        pathParams: [
            {
                name: 'newsletterId',
                type: 'integer',
                required: true,
                description: 'The newsletter ID.',
            },
        ],
        bodyFields: [
            {
                name: 'content_variables',
                type: 'object',
                required: true,
                description:
                    'Map of variable name to default text (maximum 255 characters each). Send {} to clear.',
            },
        ],
        requestExample: {
            content_variables: { company: 'Acme', first_name: 'Friend' },
        },
        response: {
            status: 200,
            body: {
                data: {
                    newsletter_id: 12,
                    detected_variables: ['company', 'first_name'],
                    content_variables: {
                        company: 'Acme',
                        first_name: 'Friend',
                    },
                },
            },
        },
    },
];

const { activeSection, sidebarItems } =
    useDocumentationSidebar('api-newsletters');
</script>

<template>
    <Head title="Newsletters API: Create and Send Email Campaigns">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Newsletters API reference. Create, update, and send newsletters, send a test mail, pick an A/B test winner, preview the audience, and set content variables."
        />
    </Head>

    <DocumentationLayout
        :active-section="activeSection"
        :sidebar-items="sidebarItems"
    >
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Newsletters</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Create and manage newsletters. Use status "draft" to save
                without sending, or "live" to send immediately.
            </p>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`newsletters-${endpoint.method.toLowerCase()}${endpoint.path.includes('{') ? '-single' : ''}`"
            />
        </div>
    </DocumentationLayout>
</template>
