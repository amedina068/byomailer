<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { KeyRound, Plug, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';

import CodeBlock from '@/components/documentation/CodeBlock.vue';
import FieldList from '@/components/documentation/FieldList.vue';
import type { Field } from '@/components/documentation/FieldList.vue';
import McpClientSetup from '@/components/documentation/McpClientSetup.vue';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useAppUrl } from '@/composables/useAppUrl';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const { activeSection, sidebarItems } = useDocumentationSidebar('mcp-server');

interface McpTool {
    id: string;
    name: string;
    description: string;
    arguments: Field[];
    returns: Field[];
    exampleResult: Record<string, unknown>;
}

const metricsFields: Field[] = [
    { name: 'total', type: 'integer', description: 'Recipients reached.' },
    { name: 'sent', type: 'integer', description: 'Messages handed to SES.' },
    { name: 'delivered', type: 'integer', description: 'Confirmed delivered.' },
    { name: 'pending', type: 'integer', description: 'Not yet sent.' },
    { name: 'opened', type: 'integer', description: 'Opened at least once.' },
    { name: 'bounced', type: 'integer', description: 'Hard or soft bounces.' },
    { name: 'unsubscribed', type: 'integer', description: 'Unsubscribed from this mail.' },
    { name: 'complained', type: 'integer', description: 'Marked as spam.' },
    { name: 'delivery_rate', type: 'float|null', description: 'delivered / sent as a percentage.' },
    { name: 'open_rate', type: 'float|null', description: 'opened / sent as a percentage.' },
    { name: 'unsubscribe_rate', type: 'float|null', description: 'unsubscribed / sent as a percentage.' },
];

const sequenceStatsFields: Field[] = [
    { name: 'contacts', type: 'integer', description: 'Distinct contacts enrolled.' },
    { name: 'unsubscribed', type: 'integer', description: 'Distinct contacts who unsubscribed.' },
    { name: 'clicked', type: 'integer', description: 'Distinct contacts who clicked.' },
];

const messageFields: Field[] = [
    { name: 'subject', type: 'string', description: 'Subject line, may contain {{ variables }}.' },
    { name: 'preview_text', type: 'string|null', description: 'Inbox preheader.' },
    { name: 'html_content', type: 'string', description: 'Rendered HTML body.' },
    { name: 'text_content', type: 'string', description: 'Plain-text body.' },
    { name: 'email_identity', type: 'object|null', description: 'Sending identity (from name/email).' },
];

const identityFields: Field[] = [
    { name: 'id', type: 'integer', description: 'Identity id.' },
    { name: 'email', type: 'string', description: 'Sender email address.' },
    { name: 'name', type: 'string|null', description: 'Sender display name.' },
    { name: 'domain', type: 'string', description: 'Domain registered in SES.' },
    { name: 'identity_status', type: 'string', description: 'pending, verifying, verified or failed. Only verified identities can send.' },
    { name: 'dkim_status', type: 'string', description: 'PENDING or SUCCESS.' },
    { name: 'mail_from_status', type: 'string', description: 'PENDING or SUCCESS.' },
    { name: 'open_tracking_enabled', type: 'boolean', description: 'Whether opens are tracked.' },
    { name: 'click_tracking_enabled', type: 'boolean', description: 'Whether clicks are tracked.' },
    { name: 'verified_at', type: 'string|null', description: 'ISO-8601 timestamp of verification.' },
    { name: 'last_status_check_at', type: 'string|null', description: 'ISO-8601 timestamp of the last AWS status check.' },
];

const identityDetailFields: Field[] = [
    ...identityFields,
    { name: 'mail_from_subdomain', type: 'string|null', description: 'MAIL FROM subdomain, for example mas.example.com.' },
    { name: 'region', type: 'string', description: 'AWS region of the identity.' },
    { name: 'custom_link_domain', type: 'string|null', description: 'Custom link domain, when configured.' },
    { name: 'custom_link_domain_verified', type: 'boolean', description: 'Whether the custom link domain is verified.' },
    { name: 'dns_records', type: 'array', description: 'DNS records to publish: type, name, value, priority, purpose (dkim, mail_from, dmarc) and verified.' },
];

const identityExample = {
    id: 3,
    email: 'hello@example.com',
    name: 'Example Team',
    domain: 'example.com',
    identity_status: 'verifying',
    dkim_status: 'PENDING',
    mail_from_status: 'PENDING',
    open_tracking_enabled: true,
    click_tracking_enabled: true,
    verified_at: null,
    last_status_check_at: '2026-09-19T10:00:00+00:00',
};

const identityDetailExample = {
    ...identityExample,
    mail_from_subdomain: 'mas.example.com',
    region: 'us-east-1',
    custom_link_domain: null,
    custom_link_domain_verified: false,
    dns_records: [
        { type: 'CNAME', name: 'abc123._domainkey.example.com', value: 'abc123.dkim.amazonses.com', priority: null, purpose: 'dkim', verified: false },
        { type: 'MX', name: 'mas.example.com', value: 'feedback-smtp.us-east-1.amazonses.com', priority: 10, purpose: 'mail_from', verified: false },
        { type: 'TXT', name: 'mas.example.com', value: 'v=spf1 include:amazonses.com ~all', priority: null, purpose: 'mail_from', verified: false },
        { type: 'TXT', name: '_dmarc.example.com', value: 'v=DMARC1; p=none;', priority: null, purpose: 'dmarc', verified: false },
    ],
};

const automationFields: Field[] = [
    { name: 'id', type: 'integer', description: 'Automation id.' },
    { name: 'name', type: 'string', description: 'Automation name.' },
    { name: 'type', type: 'string', description: 'Always simple.' },
    { name: 'is_live', type: 'boolean', description: 'Whether the automation runs on contacts now.' },
    { name: 'condition', type: 'object', description: 'The trigger: type and value (list, tag or sequence id), or attribute, operator and value for attribute_changed. Has a name for a list, tag or sequence.' },
    { name: 'actions', type: 'array', description: 'The actions, in order: type and value (tag, list or sequence id), with a name.' },
    { name: 'created_at', type: 'string|null', description: 'ISO-8601 creation time.' },
    { name: 'updated_at', type: 'string|null', description: 'ISO-8601 time of the last change.' },
];

const automationArguments: Field[] = [
    { name: 'name', type: 'string', required: true, description: 'Automation name.' },
    { name: 'type', type: 'string', description: 'simple (default). No other type exists.' },
    { name: 'condition', type: 'object', required: true, description: 'The trigger: type (contact_added_to_list, removed_from_list, tag_added, removed_tag, added_to_sequence, sequence_finished or attribute_changed) and value (the id). For attribute_changed, send attribute and operator (changed_to with a value, or changed).' },
    { name: 'actions', type: 'array', required: true, description: 'One or more actions: type (add_tag, remove_tag, add_list, remove_list or add_sequence) and value (the id). add_sequence needs a live sequence.' },
];

const automationExample = {
    id: 12,
    name: 'Tag new customers',
    type: 'simple',
    is_live: false,
    condition: { type: 'contact_added_to_list', value: 8 },
    actions: [{ type: 'add_tag', value: 5 }],
    created_at: '2026-09-23T10:00:00+00:00',
    updated_at: '2026-09-23T10:00:00+00:00',
};

const tools: McpTool[] = [
    {
        id: 'tool-list-newsletters',
        name: 'list-newsletters',
        description: 'List the account\'s newsletters so you can find an id to read stats or content for.',
        arguments: [
            { name: 'status', type: 'string', description: 'Filter by status: draft, scheduled, queued, ab_testing, sent or failed.' },
            { name: 'search', type: 'string', description: 'Case-insensitive match against title and subject.' },
            { name: 'limit', type: 'integer', description: 'Max rows to return, 1-100 (default 25).' },
        ],
        returns: [
            {
                name: 'newsletters',
                type: 'array',
                description: 'Matching newsletters, newest first.',
                children: [
                    { name: 'id', type: 'integer', description: 'Newsletter id.' },
                    { name: 'title', type: 'string', description: 'Internal title.' },
                    { name: 'status', type: 'string', description: 'Current status.' },
                    { name: 'subject', type: 'string|null', description: 'Subject of the primary message.' },
                    { name: 'sent_at', type: 'string|null', description: 'ISO 8601 send time.' },
                ],
            },
            { name: 'count', type: 'integer', description: 'Number of rows returned.' },
        ],
        exampleResult: {
            newsletters: [
                { id: 42, title: 'June product update', status: 'sent', subject: "What's new this month", sent_at: '2026-06-01T09:00:00+00:00' },
            ],
            count: 1,
        },
    },
    {
        id: 'tool-newsletter-stats',
        name: 'newsletter-stats',
        description: 'Delivery and engagement metrics for one newsletter. Includes A/B variant metrics when A/B testing is enabled.',
        arguments: [
            { name: 'newsletter_id', type: 'integer', required: true, description: 'The newsletter to read stats for.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Newsletter id.' },
            { name: 'title', type: 'string', description: 'Internal title.' },
            { name: 'status', type: 'string', description: 'Current status.' },
            { name: 'sent_at', type: 'string|null', description: 'ISO 8601 send time.' },
            { name: 'metrics', type: 'object', description: 'Aggregate engagement metrics.', children: metricsFields },
            { name: 'ab_metrics', type: 'object|null', description: 'Per-variant open/click metrics (a and b) when A/B testing is on, otherwise null.' },
        ],
        exampleResult: {
            id: 42,
            title: 'June product update',
            status: 'sent',
            sent_at: '2026-06-01T09:00:00+00:00',
            metrics: {
                total: 1240, sent: 1240, delivered: 1228, pending: 0, opened: 612,
                bounced: 12, unsubscribed: 4, complained: 0,
                delivery_rate: 99, open_rate: 49.4, unsubscribe_rate: 0.3,
            },
            ab_metrics: null,
        },
    },
    {
        id: 'tool-newsletter-content',
        name: 'newsletter-content',
        description: 'Full subject, sender and body of one newsletter, plus the B variant content when A/B testing is enabled.',
        arguments: [
            { name: 'newsletter_id', type: 'integer', required: true, description: 'The newsletter to read content for.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Newsletter id.' },
            { name: 'title', type: 'string', description: 'Internal title.' },
            { name: 'status', type: 'string', description: 'Current status.' },
            { name: 'subject', type: 'string|null', description: 'Subject line.' },
            { name: 'preview_text', type: 'string|null', description: 'Inbox preheader.' },
            { name: 'from_email', type: 'string|null', description: 'Sender email.' },
            { name: 'from_name', type: 'string|null', description: 'Sender name.' },
            { name: 'html_content', type: 'string|null', description: 'Rendered HTML body.' },
            { name: 'text_content', type: 'string|null', description: 'Plain-text body.' },
            { name: 'content_variables', type: 'object', description: 'Default values for template variables.' },
            { name: 'ab_test_enabled', type: 'boolean', description: 'Whether A/B testing is on.' },
            { name: 'subject_b / preview_text_b / html_content_b', type: 'string|null', description: 'B variant content when A/B testing is enabled.' },
        ],
        exampleResult: {
            id: 42,
            title: 'June product update',
            status: 'sent',
            subject: "What's new this month",
            preview_text: 'Read receipts, faster sends, and more',
            from_email: 'hello@acme.com',
            from_name: 'Acme',
            html_content: '<p>Hi {{ name }}, here is what shipped…</p>',
            text_content: 'Hi {{ name }}, here is what shipped…',
            content_variables: { name: 'there' },
            ab_test_enabled: false,
            subject_b: null,
            preview_text_b: null,
            html_content_b: null,
        },
    },
    {
        id: 'tool-view-newsletter-filters',
        name: 'view-newsletter-filters',
        description: 'Describe the audience targeting filters available to your account: the filter schema (categories, operators, modes) plus the concrete tags, lists, topics, attribute keys, countries, timezones and newsletters you can reference. Use it before building a filters array for calculate-newsletter-contacts or create-newsletter.',
        arguments: [],
        returns: [
            { name: 'schema', type: 'object', description: 'Allowed categories, operators, modes and windows.' },
            { name: 'available', type: 'object', description: 'Your account\'s tags, lists, topics, attributes, countries, timezones and newsletters.' },
        ],
        exampleResult: {
            schema: {
                categories: ['attributes', 'tags', 'lists', 'topics', 'date', 'engagement', 'location'],
                modes: ['all', 'any', 'none'],
                engagement_operators: ['opened', 'clicked', 'received', 'not_opened', 'not_clicked', 'not_received'],
            },
            available: {
                tags: ['vip', 'beta'],
                lists: [{ id: 3, name: 'Subscribers' }],
                topics: [{ id: 7, name: 'Product updates' }],
                attributes: ['plan', 'country'],
                countries: ['US', 'GB'],
                timezones: ['America/New_York'],
                newsletters: [{ id: 42, title: 'June product update' }],
            },
        },
    },
    {
        id: 'tool-calculate-newsletter-contacts',
        name: 'calculate-newsletter-contacts',
        description: 'Count how many contacts a set of audience filters would reach, without creating or sending anything. Pass the same filters you would use with create-newsletter; omit them to count every active (non-unsubscribed) contact.',
        arguments: [
            { name: 'filters', type: 'array', description: 'Targeting filters (max 20). Same shape as create-newsletter. Omit to count all active contacts.' },
        ],
        returns: [
            { name: 'count', type: 'integer', description: 'Contacts that match the filters.' },
            { name: 'applied_filters', type: 'array', description: 'The sanitized filters that were actually applied.' },
        ],
        exampleResult: {
            count: 318,
            applied_filters: [
                { connector: 'and', category: 'tags', mode: 'any', values: ['vip'] },
            ],
        },
    },
    {
        id: 'tool-create-newsletter',
        name: 'create-newsletter',
        description: 'Create a draft newsletter. Drafts are never sent automatically. Schedule a draft with schedule-newsletter after the user confirms. Optionally attach a sender identity, template, audience filters and content variable defaults.',
        arguments: [
            { name: 'title', type: 'string', description: 'Internal title. Defaults to "Untitled newsletter".' },
            { name: 'subject', type: 'string', description: 'Email subject line. Supports {{ variables }}.' },
            { name: 'preview_text', type: 'string', description: 'Inbox preheader text.' },
            { name: 'html_content', type: 'string', description: 'HTML body. Plain-text is derived automatically.' },
            { name: 'email_identity_id', type: 'integer', description: 'Verified sender identity id. Required before the draft can be scheduled or test-mailed.' },
            { name: 'email_template_id', type: 'integer', description: 'Optional template id to wrap the content.' },
            { name: 'filters', type: 'array', description: 'Audience targeting filters (max 20). Omit to target every active contact.' },
            { name: 'content_variables', type: 'object', description: 'Default text for each {{ variable }} (maximum 255 characters each). A default fills the variable when the contact has no value. The object replaces all saved defaults.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'New newsletter id.' },
            { name: 'title', type: 'string', description: 'Internal title.' },
            { name: 'status', type: 'string', description: 'Always "draft".' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 57,
            title: 'June product update',
            status: 'draft',
            message: 'Draft newsletter #57 created.',
        },
    },
    {
        id: 'tool-update-newsletter',
        name: 'update-newsletter',
        description: 'Update a draft or scheduled newsletter. A scheduled newsletter is live: the tool refuses the change until the user confirms it and you send confirm_live set to true. A newsletter that is sending or sent is locked. The status does not change here: use schedule-newsletter. Only the fields you pass are changed; omitted fields keep their current values.',
        arguments: [
            { name: 'newsletter_id', type: 'integer', required: true, description: 'The draft or scheduled newsletter to update. Find ids with list-newsletters.' },
            { name: 'title', type: 'string', description: 'Internal title.' },
            { name: 'subject', type: 'string', description: 'Email subject line. Supports {{ variables }}.' },
            { name: 'preview_text', type: 'string', description: 'Inbox preheader text.' },
            { name: 'html_content', type: 'string', description: 'HTML body. Plain-text is derived automatically.' },
            { name: 'email_identity_id', type: 'integer', description: 'Verified sender identity id. Required before the draft can be scheduled or test-mailed.' },
            { name: 'email_template_id', type: 'integer', description: 'Optional template id to wrap the content.' },
            { name: 'filters', type: 'array', description: 'Audience targeting filters (max 20). Omit to leave the audience unchanged.' },
            { name: 'content_variables', type: 'object', description: 'Default text for each {{ variable }} (maximum 255 characters each). A default fills the variable when the contact has no value. The object replaces all saved defaults.' },
            { name: 'confirm_live', type: 'boolean', description: 'Required for a live record. Set it to true only after the user confirms the change.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Newsletter id.' },
            { name: 'title', type: 'string', description: 'Internal title.' },
            { name: 'status', type: 'string', description: 'The current status: "draft" or "scheduled". It does not change.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 57,
            title: 'June product update (v2)',
            status: 'draft',
            message: 'Draft newsletter #57 updated.',
        },
    },
    {
        id: 'tool-send-newsletter-test-mail',
        name: 'send-newsletter-test-mail',
        description: 'Send a one-off test copy of a newsletter to your own email address so you can preview it. The newsletter must already have a sender identity attached. Does not send to the newsletter audience.',
        arguments: [
            { name: 'newsletter_id', type: 'integer', required: true, description: 'The newsletter to send a test copy of.' },
        ],
        returns: [
            { name: 'message', type: 'string', description: 'Confirmation message.' },
            { name: 'sent_to', type: 'string', description: 'The address the test was sent to (your own).' },
        ],
        exampleResult: {
            message: 'Test email sent to you@acme.com',
            sent_to: 'you@acme.com',
        },
    },
    {
        id: 'tool-list-sequences',
        name: 'list-sequences',
        description: 'List the account\'s sequences so you can find an id to read stats or content for.',
        arguments: [
            { name: 'status', type: 'string', description: 'Filter by status: draft, live or paused. paused is the same as draft: a paused sequence has the status draft.' },
            { name: 'search', type: 'string', description: 'Case-insensitive match against the sequence name.' },
            { name: 'limit', type: 'integer', description: 'Max rows to return, 1-100 (default 25).' },
        ],
        returns: [
            {
                name: 'sequences',
                type: 'array',
                description: 'Matching sequences, live first.',
                children: [
                    { name: 'id', type: 'integer', description: 'Sequence id.' },
                    { name: 'name', type: 'string', description: 'Sequence name.' },
                    { name: 'status', type: 'string', description: 'Current status.' },
                    { name: 'steps_count', type: 'integer', description: 'Number of steps.' },
                    { name: 'contacts_count', type: 'integer', description: 'Enrolled contacts.' },
                ],
            },
            { name: 'count', type: 'integer', description: 'Number of rows returned.' },
        ],
        exampleResult: {
            sequences: [
                { id: 7, name: 'Onboarding', status: 'live', steps_count: 4, contacts_count: 318 },
            ],
            count: 1,
        },
    },
    {
        id: 'tool-create-sequence',
        name: 'create-sequence',
        description: 'Create a draft sequence (automated drip campaign). Sequences are always created as drafts. Activate a sequence with set-sequence-status after the user confirms. Pass filters to limit which contacts receive the sequence, and content_variables to set the default of each {{ variable }}. Add steps with create-sequence-step.',
        arguments: [
            { name: 'name', type: 'string', required: true, description: 'Name for the sequence.' },
            { name: 'filters', type: 'array', description: 'Sequence filters in the view-newsletter-filters format. Only contacts who match them receive the sequence. Omit to target every active contact.' },
            { name: 'content_variables', type: 'object', description: 'Default text for each {{ variable }} (maximum 255 characters each). A default fills the variable when the contact has no value. The object replaces all saved defaults.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'New sequence id.' },
            { name: 'name', type: 'string', description: 'Sequence name.' },
            { name: 'status', type: 'string', description: 'Always "draft".' },
            { name: 'filters', type: 'array', description: 'The saved sequence filters. Filters that point to lists, tags or topics of another account are removed.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 12,
            name: 'Onboarding drip',
            status: 'draft',
            filters: [{ connector: 'and', category: 'tags', mode: 'any', values: ['vip'] }],
            message: 'Draft sequence #12 created. Add steps with create-sequence-step.',
        },
    },
    {
        id: 'tool-update-sequence',
        name: 'update-sequence',
        description: 'Update a sequence of any status. A live sequence sends to real contacts, so the tool refuses the change until the user confirms it and you send confirm_live set to true. The status does not change here: use set-sequence-status. Only the fields you pass are changed. Filters and a saved segment are exclusive, so passing filters removes a saved segment from the sequence.',
        arguments: [
            { name: 'sequence_id', type: 'integer', required: true, description: 'Id of the sequence to update. Discover ids with list-sequences.' },
            { name: 'name', type: 'string', description: 'New name for the sequence.' },
            { name: 'filters', type: 'array', description: 'Sequence filters in the view-newsletter-filters format. The array replaces the current filters. Pass an empty array to clear them.' },
            { name: 'content_variables', type: 'object', description: 'Default text for each {{ variable }} (maximum 255 characters each). A default fills the variable when the contact has no value. The object replaces all saved defaults.' },
            { name: 'confirm_live', type: 'boolean', description: 'Required for a live record. Set it to true only after the user confirms the change.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Sequence id.' },
            { name: 'name', type: 'string', description: 'Sequence name.' },
            { name: 'status', type: 'string', description: 'The current status: "draft" or "live". A paused record has the status "draft". It does not change.' },
            { name: 'filters', type: 'array', description: 'The saved sequence filters.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 12,
            name: 'Onboarding drip — VIP',
            status: 'draft',
            filters: [{ connector: 'and', category: 'lists', mode: 'any', values: [3] }],
            message: 'Draft sequence #12 updated.',
        },
    },
    {
        id: 'tool-create-sequence-step',
        name: 'create-sequence-step',
        description: 'Add a draft step to an existing sequence. Steps are always created as drafts. Activate a step with set-sequence-step-status after the user confirms. Set the delay before it sends relative to the previous step.',
        arguments: [
            { name: 'sequence_id', type: 'integer', required: true, description: 'The sequence to add the step to.' },
            { name: 'delay_type', type: 'string', required: true, description: 'Unit for the delay: "days" or "hours".' },
            { name: 'delay_days', type: 'integer', required: true, description: 'Days (max 365) or hours (max 8760) after the previous step. Use 0 to send on enrollment for the first step.' },
            { name: 'email_identity_id', type: 'integer', required: true, description: 'Verified sender identity id to send this step from.' },
            { name: 'subject', type: 'string', description: 'Email subject line. Supports {{ variables }}.' },
            { name: 'preview_text', type: 'string', description: 'Inbox preheader text.' },
            { name: 'html_content', type: 'string', description: 'HTML body. Plain-text is derived automatically.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'New step id.' },
            { name: 'sequence_id', type: 'integer', description: 'Parent sequence id.' },
            { name: 'status', type: 'string', description: 'Always "draft".' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 48,
            sequence_id: 12,
            status: 'draft',
            message: 'Draft step #48 added to sequence #12.',
        },
    },
    {
        id: 'tool-update-sequence-step',
        name: 'update-sequence-step',
        description: 'Update a step of any status. A live step sends to real contacts, so the tool refuses the change until the user confirms it and you send confirm_live set to true. The status does not change here: use set-sequence-step-status. Only the fields you pass are changed; omitted fields keep their current values. Pair delay_type and delay_days to reschedule.',
        arguments: [
            { name: 'sequence_step_id', type: 'integer', required: true, description: 'Id of the step to update. Discover ids with sequence-content.' },
            { name: 'delay_type', type: 'string', description: 'Unit for the delay: "days" or "hours". Required together with delay_days.' },
            { name: 'delay_days', type: 'integer', description: 'Days (max 365) or hours (max 8760) after the previous step. Required together with delay_type.' },
            { name: 'email_identity_id', type: 'integer', description: 'Verified sender identity id to send this step from.' },
            { name: 'email_template_id', type: 'integer', description: 'Email template id to wrap the content. Pass null to remove the template.' },
            { name: 'subject', type: 'string', description: 'Email subject line. Supports {{ variables }}.' },
            { name: 'preview_text', type: 'string', description: 'Inbox preheader text.' },
            { name: 'html_content', type: 'string', description: 'HTML body. Plain-text is derived automatically.' },
            { name: 'confirm_live', type: 'boolean', description: 'Required for a live record. Set it to true only after the user confirms the change.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Step id.' },
            { name: 'sequence_id', type: 'integer', description: 'Parent sequence id.' },
            { name: 'status', type: 'string', description: 'The current status: "draft" or "live". A paused record has the status "draft". It does not change.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 48,
            sequence_id: 12,
            status: 'draft',
            message: 'Draft step #48 updated.',
        },
    },
    {
        id: 'tool-sequence-stats',
        name: 'sequence-stats',
        description: 'Sequence-level totals and aggregate mail metrics across all steps. For a per-step breakdown use sequence-step-stats.',
        arguments: [
            { name: 'sequence_id', type: 'integer', required: true, description: 'The sequence to read stats for.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Sequence id.' },
            { name: 'name', type: 'string', description: 'Sequence name.' },
            { name: 'status', type: 'string', description: 'Current status.' },
            { name: 'stats', type: 'object', description: 'Sequence-wide contact/unsubscribe/click totals.', children: sequenceStatsFields },
            { name: 'mail_metrics', type: 'object', description: 'Aggregate metrics across every step.', children: metricsFields },
        ],
        exampleResult: {
            id: 7,
            name: 'Onboarding',
            status: 'live',
            stats: { contacts: 318, unsubscribed: 6, clicked: 121 },
            mail_metrics: {
                total: 1180, sent: 1180, delivered: 1170, pending: 0, opened: 540,
                bounced: 10, unsubscribed: 6, complained: 0,
                delivery_rate: 99.2, open_rate: 45.8, unsubscribe_rate: 0.5,
            },
        },
    },
    {
        id: 'tool-sequence-content',
        name: 'sequence-content',
        description: 'Full content of a sequence and every one of its steps.',
        arguments: [
            { name: 'sequence_id', type: 'integer', required: true, description: 'The sequence to read content for.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Sequence id.' },
            { name: 'name', type: 'string', description: 'Sequence name.' },
            { name: 'status', type: 'string', description: 'Current status.' },
            { name: 'contacts_count', type: 'integer', description: 'Enrolled contacts.' },
            { name: 'steps_count', type: 'integer', description: 'Number of steps.' },
            { name: 'content_variables', type: 'object', description: 'Default values for template variables.' },
            {
                name: 'steps',
                type: 'array',
                description: 'Steps in send order.',
                children: [
                    { name: 'id', type: 'integer', description: 'Step id.' },
                    { name: 'delay_days', type: 'integer', description: 'Delay before this step.' },
                    { name: 'delay_type', type: 'string', description: 'Unit of the delay (e.g. days).' },
                    { name: 'status', type: 'string', description: 'Step status.' },
                    { name: 'message', type: 'object|null', description: 'Step message content.', children: messageFields },
                ],
            },
        ],
        exampleResult: {
            id: 7,
            name: 'Onboarding',
            status: 'live',
            contacts_count: 318,
            steps_count: 4,
            content_variables: { name: 'there' },
            steps: [
                {
                    id: 31, delay_days: 0, delay_type: 'days', status: 'live',
                    message: { id: 88, subject: 'Welcome aboard', preview_text: 'Glad you are here', html_content: '<p>Hi {{ name }}…</p>', text_content: 'Hi {{ name }}…', email_identity_id: 3 },
                },
            ],
        },
    },
    {
        id: 'tool-send-sequence-step-test-mail',
        name: 'send-sequence-step-test-mail',
        description: 'Send a one-off test copy of a single sequence step to your own email address so you can preview it. The step must already have a sender identity attached. Does not send to the sequence audience and does not start or advance the sequence for anyone.',
        arguments: [
            { name: 'sequence_id', type: 'integer', required: true, description: 'The sequence the step belongs to.' },
            { name: 'step_id', type: 'integer', required: true, description: 'The step to send a test copy of.' },
        ],
        returns: [
            { name: 'message', type: 'string', description: 'Confirmation message.' },
            { name: 'sent_to', type: 'string', description: 'The address the test was sent to (your own).' },
        ],
        exampleResult: {
            message: 'Test email sent to you@acme.com',
            sent_to: 'you@acme.com',
        },
    },
    {
        id: 'tool-sequence-step-content',
        name: 'sequence-step-content',
        description: 'Full content of a single sequence step.',
        arguments: [
            { name: 'sequence_id', type: 'integer', required: true, description: 'The sequence the step belongs to.' },
            { name: 'step_id', type: 'integer', required: true, description: 'The step to read content for.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Step id.' },
            { name: 'sequence_id', type: 'integer', description: 'Parent sequence id.' },
            { name: 'delay_days', type: 'integer', description: 'Delay before this step.' },
            { name: 'delay_type', type: 'string', description: 'Unit of the delay (e.g. days).' },
            { name: 'status', type: 'string', description: 'Step status.' },
            { name: 'message', type: 'object|null', description: 'Step message content.', children: messageFields },
        ],
        exampleResult: {
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
                email_identity: { id: 3, name: 'Acme', email: 'hello@acme.com' },
            },
        },
    },
    {
        id: 'tool-sequence-step-stats',
        name: 'sequence-step-stats',
        description: 'Delivery and engagement stats for a single sequence step. Contacts are counted in the furthest step they have reached.',
        arguments: [
            { name: 'sequence_id', type: 'integer', required: true, description: 'The sequence the step belongs to.' },
            { name: 'step_id', type: 'integer', required: true, description: 'The step to read stats for.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Step id.' },
            { name: 'sequence_id', type: 'integer', description: 'Parent sequence id.' },
            { name: 'subject', type: 'string|null', description: 'Step subject.' },
            { name: 'delay_days', type: 'integer', description: 'Delay before this step.' },
            { name: 'delay_type', type: 'string', description: 'Unit of the delay (e.g. days).' },
            { name: 'status', type: 'string', description: 'Step status.' },
            { name: 'stats', type: 'object', description: 'Contacts/unsubscribed/clicked for this step.', children: sequenceStatsFields },
            { name: 'mail_metrics', type: 'object', description: 'Mail metrics for this step.', children: metricsFields },
        ],
        exampleResult: {
            id: 31,
            sequence_id: 7,
            subject: 'Welcome aboard',
            delay_days: 0,
            delay_type: 'days',
            status: 'live',
            stats: { contacts: 318, unsubscribed: 2, clicked: 80 },
            mail_metrics: {
                total: 318, sent: 318, delivered: 316, pending: 0, opened: 190,
                bounced: 2, unsubscribed: 2, complained: 0,
                delivery_rate: 99.4, open_rate: 59.7, unsubscribe_rate: 0.6,
            },
        },
    },
    {
        id: 'tool-create-tag',
        name: 'create-tag',
        description: 'Create a tag for the account. Tags label contacts and build audiences. Tag names are unique per account — creating a name that already exists returns an error.',
        arguments: [
            { name: 'name', type: 'string', required: true, description: 'The tag name. Must be unique within the account.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'New tag id.' },
            { name: 'name', type: 'string', description: 'The tag name.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 9,
            name: 'VIP',
            message: 'Tag #9 created.',
        },
    },
    {
        id: 'tool-find-contact',
        name: 'find-contact',
        description: 'Look up one contact by exact email. Returns that person even when they are unsubscribed, or contact null when this account has no such email.',
        arguments: [
            { name: 'email', type: 'string', required: true, description: 'Exact email address to look up in this account.' },
        ],
        returns: [
            { name: 'contact', type: 'object', description: 'The contact, or null. When present: id, email, name, unsubscribed_at, tags, and list ids.' },
        ],
        exampleResult: {
            contact: {
                id: 42,
                email: 'ada@example.com',
                name: 'Ada',
                unsubscribed_at: null,
                tags: ['VIP'],
                lists: [3],
            },
        },
    },
    {
        id: 'tool-create-contact',
        name: 'create-contact',
        description: 'Create a contact, or reuse the contact that already has this email. Optional name, tag names, and one list. A new blacklisted email is saved as unsubscribed. Tags are added and never removed. A list that requires opt-in queues the opt-in email. The subscription never changes.',
        arguments: [
            { name: 'email', type: 'string', required: true, description: 'Email address. Reuses the account contact with this exact email when one exists.' },
            { name: 'name', type: 'string', description: 'Optional display name. Omitted leaves the current name unchanged.' },
            { name: 'tags', type: 'array', description: 'Optional tag names to add. Unknown names are created. Existing tags stay. To remove tags, use bulk-update-contacts.' },
            { name: 'list_id', type: 'integer', description: 'Optional mailing list id from view-newsletter-filters.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Contact id.' },
            { name: 'created', type: 'boolean', description: 'True when a new contact row was inserted.' },
            { name: 'email', type: 'string', description: 'The contact email.' },
            { name: 'name', type: 'string', description: 'The contact name.' },
            { name: 'tags', type: 'array', description: 'Tag names on the contact.' },
            { name: 'lists', type: 'array', description: 'Mailing list ids the contact belongs to.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 42,
            email: 'ada@example.com',
            name: 'Ada',
            unsubscribed_at: null,
            tags: ['VIP'],
            lists: [3],
            created: true,
            message: 'Contact #42 created.',
        },
    },
    {
        id: 'tool-add-contact-to-list',
        name: 'add-contact-to-list',
        description: 'Add an existing contact to a mailing list without removing their other lists. A list that requires opt-in queues the opt-in email. The subscription never changes. To change many contacts at once, use bulk-update-contacts.',
        arguments: [
            { name: 'contact_id', type: 'integer', required: true, description: 'Contact id from find-contact.' },
            { name: 'list_id', type: 'integer', required: true, description: 'Mailing list id from view-newsletter-filters.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Contact id.' },
            { name: 'lists', type: 'array', description: 'Mailing list ids the contact belongs to.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 42,
            email: 'ada@example.com',
            name: 'Ada',
            unsubscribed_at: null,
            tags: ['VIP'],
            lists: [3, 8],
            message: 'Contact #42 added to list #8.',
        },
    },
    {
        id: 'tool-add-tags-to-contact',
        name: 'add-tags-to-contact',
        description: 'Add tags to an existing contact by name. Existing tags stay. A name that does not exist yet is created. To remove tags, or to change many contacts at once, use bulk-update-contacts.',
        arguments: [
            { name: 'contact_id', type: 'integer', required: true, description: 'Contact id from find-contact.' },
            { name: 'tags', type: 'array', required: true, description: 'Tag names to add.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Contact id.' },
            { name: 'tags', type: 'array', description: 'Tag names on the contact.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 42,
            email: 'ada@example.com',
            name: 'Ada',
            unsubscribed_at: null,
            tags: ['Customer', 'VIP'],
            lists: [3],
            message: 'Tags added to contact #42.',
        },
    },
    {
        id: 'tool-remove-contact-from-list',
        name: 'remove-contact-from-list',
        description: 'Remove a contact from one mailing list. Their other lists stay, and the contact is not deleted. The removal runs the live list automations of the account.',
        arguments: [
            { name: 'contact_id', type: 'integer', required: true, description: 'Contact id from find-contact.' },
            { name: 'list_id', type: 'integer', required: true, description: 'Mailing list id to remove them from.' },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Contact id.' },
            { name: 'lists', type: 'array', description: 'Mailing list ids the contact still belongs to.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            id: 42,
            email: 'ada@example.com',
            name: 'Ada',
            unsubscribed_at: null,
            tags: ['VIP'],
            lists: [3],
            message: 'Contact #42 removed from list #8.',
        },
    },
    {
        id: 'tool-list-contacts',
        name: 'list-contacts',
        description: 'List the contacts of the account, oldest id first. Filter them with the view-newsletter-filters filters and with a search on the name or email. Unsubscribed contacts are excluded unless include_unsubscribed is true. Read the next page with after_id. The cursor stays correct when you change the listed contacts between pages.',
        arguments: [
            { name: 'filters', type: 'array', description: 'Audience filters in the view-newsletter-filters format (max 20). Omit to list every contact.' },
            { name: 'search', type: 'string', description: 'Optional case-insensitive search on the name or email.' },
            { name: 'include_unsubscribed', type: 'boolean', description: 'Include unsubscribed contacts. Default false.' },
            { name: 'after_id', type: 'integer', description: 'Return only contacts with an id above this value. Send next_after_id from the previous page.' },
            { name: 'per_page', type: 'integer', description: 'Page size (1-100, default 25).' },
        ],
        returns: [
            { name: 'contacts', type: 'array', description: 'Contacts with id, email, name, unsubscribed_at, tag names, list ids, custom attributes and created_at.' },
            { name: 'total', type: 'integer', description: 'All contacts that match the filters and the search.' },
            { name: 'has_more', type: 'boolean', description: 'True when another page exists.' },
            { name: 'next_after_id', type: 'integer', description: 'The after_id for the next page, or null.' },
            { name: 'applied_filters', type: 'array', description: 'The sanitized filters that were applied.' },
        ],
        exampleResult: {
            contacts: [
                {
                    id: 42,
                    email: 'ada@example.com',
                    name: 'Ada',
                    unsubscribed_at: null,
                    tags: ['VIP'],
                    lists: [3],
                    attributes: { plan: 'pro' },
                    created_at: '2026-10-01T09:30:00+00:00',
                },
            ],
            total: 318,
            has_more: true,
            next_after_id: 42,
            applied_filters: [],
        },
    },
    {
        id: 'tool-bulk-update-contacts',
        name: 'bulk-update-contacts',
        description: 'Update up to 100 existing contacts in one call. Each item names one contact by contact_id or exact email and has its own changes. Only the fields you send change. Tag, list and attribute changes run the live automations of the account. A failed item does not stop the other items.',
        arguments: [
            { name: 'contacts', type: 'array', required: true, description: 'The contacts to update (1-100). Each item: contact_id or email, and any of name, add_tags, remove_tags, set_attributes, remove_attributes, add_to_lists, remove_from_lists.' },
        ],
        returns: [
            { name: 'updated', type: 'integer', description: 'Number of items that were updated.' },
            { name: 'failed', type: 'integer', description: 'Number of items that failed.' },
            { name: 'results', type: 'array', description: 'One result per item: index, status (updated or failed), and the contact tags, lists and attributes after the update, or the error.' },
        ],
        exampleResult: {
            updated: 1,
            failed: 1,
            results: [
                {
                    index: 0,
                    status: 'updated',
                    contact_id: 42,
                    email: 'ada@example.com',
                    name: 'Ada',
                    tags: ['Customer'],
                    lists: [3],
                    attributes: { plan: 'pro', seats: 5 },
                },
                {
                    index: 1,
                    status: 'failed',
                    contact_id: null,
                    email: 'missing@example.com',
                    error: 'No contact found with email missing@example.com.',
                },
            ],
        },
    },
    {
        id: 'tool-list-email-identities',
        name: 'list-email-identities',
        description: 'List the sender identities (verified sending domains) of the account with their verification status, DKIM and MAIL FROM status and tracking flags. Only identities with identity_status verified can send mail.',
        arguments: [
            { name: 'identity_status', type: 'string', description: 'Optional filter: pending, verifying, verified or failed.' },
            { name: 'search', type: 'string', description: 'Optional search against email, name and domain.' },
            { name: 'limit', type: 'integer', description: 'Maximum identities to return (1-100, default 25).' },
        ],
        returns: [
            { name: 'identities', type: 'array', description: 'Identity summaries. Each item carries the fields of email-identity-details minus mail_from_subdomain, region, custom link domain and dns_records.' },
            { name: 'count', type: 'integer', description: 'Number of identities returned.' },
        ],
        exampleResult: {
            identities: [identityExample],
            count: 1,
        },
    },
    {
        id: 'tool-email-identity-details',
        name: 'email-identity-details',
        description: 'Show one sender identity in full, including the DNS records (DKIM CNAMEs, MAIL FROM MX/TXT and DMARC TXT) the domain owner must publish, each with a verified flag. Use it to guide someone through DNS setup.',
        arguments: [
            { name: 'email_identity_id', type: 'integer', required: true, description: 'The identity id from list-email-identities.' },
        ],
        returns: identityDetailFields,
        exampleResult: identityDetailExample,
    },
    {
        id: 'tool-create-email-identity',
        name: 'create-email-identity',
        description: 'Create a sender identity. Registers the email domain in your own AWS SES, sets up a MAIL FROM subdomain and a per-identity configuration set, and returns the DNS records to publish. Requires AWS credentials configured in the dashboard. Set existing_aws_identity to import a domain that is already verified in SES.',
        arguments: [
            { name: 'email', type: 'string', required: true, description: 'Sender email address. Its domain is registered in SES.' },
            { name: 'name', type: 'string', description: 'Optional sender display name.' },
            { name: 'existing_aws_identity', type: 'boolean', description: 'Import a domain that already exists in SES instead of creating it. Default false.' },
            { name: 'open_tracking_enabled', type: 'boolean', description: 'Track opens. Default true.' },
            { name: 'click_tracking_enabled', type: 'boolean', description: 'Track clicks. Default true.' },
            { name: 'mail_from_subdomain', type: 'string', description: 'Optional lowercase MAIL FROM subdomain. Default mas.<domain>.' },
        ],
        returns: [
            ...identityDetailFields,
            { name: 'message', type: 'string', description: 'Human-readable confirmation with the next step.' },
        ],
        exampleResult: {
            ...identityDetailExample,
            identity_status: 'pending',
            last_status_check_at: null,
            message: 'Identity #3 created. Publish the DNS records, then call refresh-email-identity-status.',
        },
    },
    {
        id: 'tool-refresh-email-identity-status',
        name: 'refresh-email-identity-status',
        description: 'Ask AWS SES for the current verification state of an identity and sync it: identity status, DKIM status, MAIL FROM status and the verified flag on each DNS record. Call it after the DNS records are published.',
        arguments: [
            { name: 'email_identity_id', type: 'integer', required: true, description: 'The identity id.' },
        ],
        returns: [
            ...identityDetailFields,
            { name: 'message', type: 'string', description: 'Human-readable status line.' },
        ],
        exampleResult: {
            ...identityDetailExample,
            message: 'Identity #3 is verifying.',
        },
    },
    {
        id: 'tool-update-email-identity-tracking',
        name: 'update-email-identity-tracking',
        description: 'Turn open tracking and click tracking on or off for an identity. Updates the SES configuration set so future mail reports (or stops reporting) opens and clicks.',
        arguments: [
            { name: 'email_identity_id', type: 'integer', required: true, description: 'The identity id.' },
            { name: 'open_tracking_enabled', type: 'boolean', required: true, description: 'Whether to track opens.' },
            { name: 'click_tracking_enabled', type: 'boolean', required: true, description: 'Whether to track clicks.' },
        ],
        returns: [
            ...identityFields,
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            ...identityExample,
            click_tracking_enabled: false,
            message: 'Tracking updated for identity #3.',
        },
    },
    {
        id: 'tool-list-automations',
        name: 'list-automations',
        description: 'List the automations of the account with their trigger condition, actions and live flag. Condition and action values are raw ids. Use automation-details to see them with names.',
        arguments: [
            { name: 'is_live', type: 'boolean', description: 'Optional filter: true for live automations, false for paused ones.' },
            { name: 'search', type: 'string', description: 'Optional search against the automation name.' },
            { name: 'limit', type: 'integer', description: 'Maximum automations to return (1-100, default 25).' },
        ],
        returns: [
            { name: 'automations', type: 'array', description: 'Automations, newest first. Each item has the fields of automation-details without the resolved names.' },
            { name: 'count', type: 'integer', description: 'Number of automations returned.' },
        ],
        exampleResult: {
            automations: [automationExample],
            count: 1,
        },
    },
    {
        id: 'tool-automation-details',
        name: 'automation-details',
        description: 'Show one automation. Each list, tag or sequence id in the condition and the actions comes with its name, or null when the item no longer exists.',
        arguments: [
            { name: 'automation_id', type: 'integer', required: true, description: 'The automation id from list-automations.' },
        ],
        returns: automationFields,
        exampleResult: {
            ...automationExample,
            condition: { ...automationExample.condition, name: 'Customers' },
            actions: [{ ...automationExample.actions[0], name: 'VIP' }],
        },
    },
    {
        id: 'tool-view-automation-options',
        name: 'view-automation-options',
        description: 'Describe the building blocks of an automation: the condition types, the action types, and the lists, tags, sequences and contact attributes that a condition or an action can use. An add_sequence action accepts only live sequences.',
        arguments: [],
        returns: [
            { name: 'condition_types', type: 'array', description: 'Each trigger type with a description of the value it needs.' },
            { name: 'action_types', type: 'array', description: 'Each action type with a description of the value it needs.' },
            { name: 'available', type: 'object', description: 'lists, tags and sequences (id and name, sequences also have status) and attributes (names).' },
        ],
        exampleResult: {
            condition_types: [{ type: 'contact_added_to_list', description: 'A contact is added to the list. value = list id.' }],
            action_types: [{ type: 'add_tag', description: 'Add the tag to the contact. value = tag id.' }],
            available: {
                lists: [{ id: 8, name: 'Customers' }],
                tags: [{ id: 5, name: 'VIP' }],
                sequences: [{ id: 3, name: 'Onboarding', status: 'live' }],
                attributes: ['plan'],
            },
        },
    },
    {
        id: 'tool-create-automation',
        name: 'create-automation',
        description: 'Create an automation: when the condition happens to a contact, the actions run on that contact. Automations are always created paused. A paused automation can add contacts to a draft sequence. Turn it on with set-automation-status after its sequences are live.',
        arguments: automationArguments,
        returns: [
            ...automationFields.filter((field) => field.name !== 'condition' && field.name !== 'actions'),
            { name: 'condition', type: 'object', description: 'The saved trigger.' },
            { name: 'actions', type: 'array', description: 'The saved actions.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            ...automationExample,
            message: 'Paused automation #12 created. Switch it on with set-automation-status after the user confirms.',
        },
    },
    {
        id: 'tool-update-automation',
        name: 'update-automation',
        description: 'Update an automation. A live automation runs on contacts now, so the tool refuses the change until the user confirms it and you send confirm_live set to true. Only the fields that you send change. A condition replaces the full condition, and an actions array replaces all actions. The live flag does not change here: use set-automation-status.',
        arguments: [
            { name: 'automation_id', type: 'integer', required: true, description: 'The id of the automation.' },
            ...automationArguments.map((field) => ({ ...field, required: false })),
            { name: 'confirm_live', type: 'boolean', description: 'Required for a live record. Set it to true only after the user confirms the change.' },
        ],
        returns: [
            ...automationFields.filter((field) => field.name !== 'condition' && field.name !== 'actions'),
            { name: 'condition', type: 'object', description: 'The saved trigger.' },
            { name: 'actions', type: 'array', description: 'The saved actions.' },
            { name: 'message', type: 'string', description: 'Human-readable confirmation.' },
        ],
        exampleResult: {
            ...automationExample,
            name: 'Tag new customers as VIP',
            message: 'Paused automation #12 updated.',
        },
    },
    {
        id: 'tool-schedule-newsletter',
        name: 'schedule-newsletter',
        description: 'Schedule a draft newsletter at a future time, move a scheduled newsletter to a new time, or return a scheduled newsletter to draft. The first call saves nothing and returns a preview with the send time and the number of recipients. Call it again with confirm_live set to true after the user confirms. It runs the same checks as the dashboard and never sends immediately. A missing {{ unsubscribe_url }} link or a missing postal address never blocks the schedule. The result lists them in warnings.',
        arguments: [
            { name: 'newsletter_id', type: 'integer', required: true, description: 'The newsletter. Find ids with list-newsletters.' },
            { name: 'action', type: 'string', required: true, description: '"schedule" or "unschedule".' },
            { name: 'scheduled_at', type: 'string', description: 'Required for "schedule". ISO 8601 time with a UTC offset, in the future.' },
            { name: 'confirm_live', type: 'boolean', description: 'Leave it out to get a preview. Set it to true only after the user confirms the preview.' },
        ],
        returns: [
            { name: 'status', type: 'string', description: 'The current status.' },
            { name: 'recipient_count', type: 'integer', description: 'The number of contacts that the newsletter sends to.' },
            { name: 'warnings', type: 'array', description: 'Compliance warnings: no {{ unsubscribe_url }} link, or no postal address. They never block the change. Empty when there is nothing to report.' },
            { name: 'applied', type: 'boolean', description: 'false for a preview or when nothing changed. true when the change is saved.' },
            { name: 'effects', type: 'array', description: 'Only in a preview: what the change does to contacts and mails.' },
            { name: 'message', type: 'string', description: 'What happened, or the next step.' },
        ],
        exampleResult: {
            id: 57,
            status: 'draft',
            change: 'schedule',
            new_scheduled_at: '2026-11-02T14:00:00+00:00',
            recipient_count: 1840,
            applied: false,
            message: 'Preview only. No change was saved.',
        },
    },
    {
        id: 'tool-set-sequence-status',
        name: 'set-sequence-status',
        description: 'Activate or pause a sequence. The first call saves nothing and returns a preview with the enrolled contacts, the pending mails, the live steps and the live automations that add contacts to the sequence. Call it again with confirm_live set to true after the user confirms. Activation runs the same checks as the dashboard. A live step without a {{ unsubscribe_url }} link or a missing postal address never blocks the activation. The result lists them in warnings.',
        arguments: [
            { name: 'sequence_id', type: 'integer', required: true, description: 'The sequence. Find ids with list-sequences.' },
            { name: 'status', type: 'string', required: true, description: '"live" or "draft". "paused" is the same as "draft".' },
            { name: 'confirm_live', type: 'boolean', description: 'Leave it out to get a preview. Set it to true only after the user confirms the preview.' },
        ],
        returns: [
            { name: 'status', type: 'string', description: 'The current status: "live" or "draft". A paused record has the status "draft".' },
            { name: 'enrolled_contacts', type: 'integer', description: 'Only in a preview: contacts in the sequence that did not finish it.' },
            { name: 'pending_mails', type: 'integer', description: 'Only in a preview: mails that wait to send.' },
            { name: 'warnings', type: 'array', description: 'Compliance warnings: no {{ unsubscribe_url }} link, or no postal address. They never block the change. Empty when there is nothing to report.' },
            { name: 'applied', type: 'boolean', description: 'false for a preview or when nothing changed. true when the change is saved.' },
            { name: 'effects', type: 'array', description: 'Only in a preview: what the change does to contacts and mails.' },
            { name: 'message', type: 'string', description: 'What happened, or the next step.' },
        ],
        exampleResult: {
            id: 23,
            status: 'draft',
            change: 'activate',
            enrolled_contacts: 0,
            pending_mails: 0,
            live_steps: 3,
            applied: false,
            message: 'Preview only. No change was saved.',
        },
    },
    {
        id: 'tool-set-sequence-step-status',
        name: 'set-sequence-step-status',
        description: 'Activate or pause one step of a sequence. The first call saves nothing and returns a preview with the contacts in the sequence and the pending mails of the step. Call it again with confirm_live set to true after the user confirms. Activation fails when the step has no subject or body. A missing {{ unsubscribe_url }} link or a missing postal address never blocks the activation. The result lists them in warnings.',
        arguments: [
            { name: 'sequence_step_id', type: 'integer', required: true, description: 'The step. Find ids with sequence-content.' },
            { name: 'status', type: 'string', required: true, description: '"live" or "draft". "paused" is the same as "draft".' },
            { name: 'confirm_live', type: 'boolean', description: 'Leave it out to get a preview. Set it to true only after the user confirms the preview.' },
        ],
        returns: [
            { name: 'status', type: 'string', description: 'The current status: "live" or "draft". A paused record has the status "draft".' },
            { name: 'pending_mails', type: 'integer', description: 'Only in a preview: mails of this step that wait to send.' },
            { name: 'warnings', type: 'array', description: 'Compliance warnings: no {{ unsubscribe_url }} link, or no postal address. They never block the change. Empty when there is nothing to report.' },
            { name: 'applied', type: 'boolean', description: 'false for a preview or when nothing changed. true when the change is saved.' },
            { name: 'effects', type: 'array', description: 'Only in a preview: what the change does to contacts and mails.' },
            { name: 'message', type: 'string', description: 'What happened, or the next step.' },
        ],
        exampleResult: {
            id: 31,
            sequence_id: 23,
            status: 'live',
            applied: true,
            message: 'Sequence step #31 activated.',
        },
    },
    {
        id: 'tool-set-automation-status',
        name: 'set-automation-status',
        description: 'Switch an automation on or off. The first call saves nothing and returns a preview with the condition, the actions and the sequences that the automation adds contacts to. Call it again with confirm_live set to true after the user confirms. An automation can go live only when the sequences in its add_sequence actions are live.',
        arguments: [
            { name: 'automation_id', type: 'integer', required: true, description: 'The automation. Find ids with list-automations.' },
            { name: 'is_live', type: 'boolean', required: true, description: 'true to switch it on, false to pause it.' },
            { name: 'confirm_live', type: 'boolean', description: 'Leave it out to get a preview. Set it to true only after the user confirms the preview.' },
        ],
        returns: [
            { name: 'is_live', type: 'boolean', description: 'The current live flag.' },
            { name: 'target_sequences', type: 'array', description: 'Only in a preview: the sequences that the automation adds contacts to, with their status.' },
            { name: 'applied', type: 'boolean', description: 'false for a preview or when nothing changed. true when the change is saved.' },
            { name: 'effects', type: 'array', description: 'Only in a preview: what the change does to contacts and mails.' },
            { name: 'message', type: 'string', description: 'What happened, or the next step.' },
        ],
        exampleResult: {
            ...automationExample,
            is_live: true,
            applied: true,
            message: 'Automation #12 activated.',
        },
    },
];

const callExample = `{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "newsletter-stats",
    "arguments": { "newsletter_id": 42 }
  }
}`;

const appUrl = useAppUrl();
const mcpUrl = computed(() => `${appUrl.value}/mcp`);

const formatJson = (value: Record<string, unknown>): string => JSON.stringify(value, null, 2);
</script>

<template>
    <Head title="MCP Server: Connect AI Clients to Your Email Marketing">
        <meta
            head-key="description"
            name="description"
            content="Connect Claude Code, Claude Desktop, Cursor, and other AI clients to BYOMailer with the Model Context Protocol. Read stats, compose drafts, and confirm each live change."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">MCP Server</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Connect AI clients to BYOMailer through the <a href="https://modelcontextprotocol.io" target="_blank" rel="noopener" class="underline">Model Context Protocol</a>. An assistant can pull your newsletter and sequence stats and content, and help you compose a newsletter — preview the audience, save a draft and send yourself a test. It sends to your audience only after you confirm each change: the assistant first shows a preview of what changes and how many contacts it affects.
            </p>
        </div>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Endpoint &amp; Authentication</h2>
            <p class="mb-6 text-muted-foreground">
                The server is served over streamable HTTP at <code class="text-sm">{{ mcpUrl }}</code> and is authenticated with the same API tokens used by the REST API. Generate a token in your account settings and send it as a bearer token on every request. A read-only token, or a token of a team member with the Viewer role, sees only the tools that read data.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <Plug class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                            <CardTitle class="text-base">Transport</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Streamable HTTP. Point any MCP-compatible client at <code class="text-sm">/mcp</code> with an <code class="text-sm">Authorization</code> header.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <KeyRound class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                            <CardTitle class="text-base">Bearer Token</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Use a personal API token: <code class="text-sm">Authorization: Bearer YOUR_API_TOKEN</code>. Requests are rate limited.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <ShieldCheck class="h-5 w-5 text-amber-600 dark:text-amber-400" />
                            <CardTitle class="text-base">No surprise sends</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            The assistant can read your campaigns and create drafts. Before it schedules a newsletter or turns a sequence, step or automation on or off, it shows you a preview with the contacts it affects and waits for your confirmation.
                        </p>
                    </CardContent>
                </Card>

            </div>
        </section>

        <McpClientSetup :mcp-url="mcpUrl" />

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Calling a Tool</h2>
            <p class="mb-4 text-muted-foreground">
                Clients invoke tools with a standard MCP <code class="text-sm">tools/call</code> request. The <code class="text-sm">name</code> is the tool name below and <code class="text-sm">arguments</code> matches its input schema. Most clients build this request for you — you just describe what you want.
            </p>
            <CodeBlock title="tools/call" :code="callExample" lang="json" />
        </section>

        <section class="mb-4">
            <h2 class="mb-2 text-2xl font-bold">Tools</h2>
            <p class="mb-8 text-muted-foreground">
                Forty-four tools. Start with a list tool to discover ids, then read stats or content — use view-newsletter-filters, calculate-newsletter-contacts, create-newsletter, update-newsletter and send-newsletter-test-mail to compose and revise a draft newsletter, or create-sequence, create-sequence-step, update-sequence-step and send-sequence-step-test-mail to draft, revise and preview a drip campaign. create-tag adds a tag for labelling contacts. find-contact, create-contact, add-contact-to-list, add-tags-to-contact and remove-contact-from-list add people to lists and tags. list-contacts lists and filters the audience, and bulk-update-contacts changes the tags, custom attributes, name and lists of up to 100 contacts in one call. list-email-identities, email-identity-details, create-email-identity, refresh-email-identity-status and update-email-identity-tracking manage the sender identities (verified sending domains) in your own AWS SES. list-automations, automation-details, view-automation-options, create-automation and update-automation manage automations. New automations are always paused. schedule-newsletter, set-sequence-status, set-sequence-step-status and set-automation-status schedule, activate and pause records. The update tools can also edit a scheduled newsletter, a live sequence, a live step and a live automation. Each change to a live record happens only after the user confirms it.
            </p>

            <div class="space-y-10">
                <div
                    v-for="tool in tools"
                    :id="tool.id"
                    :key="tool.name"
                    class="border-b pb-10 last:border-b-0 last:pb-0"
                >
                    <div class="mb-6">
                        <code class="text-sm font-bold text-foreground">{{ tool.name }}</code>
                        <p class="mt-2 text-sm text-muted-foreground">{{ tool.description }}</p>
                    </div>

                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0 space-y-6">
                            <FieldList
                                v-if="tool.arguments.length > 0"
                                title="Arguments"
                                :fields="tool.arguments"
                            />
                            <FieldList title="Returns" :fields="tool.returns" />
                        </div>

                        <div class="min-w-0 lg:sticky lg:top-32">
                            <CodeBlock
                                title="Example result"
                                :code="formatJson(tool.exampleResult)"
                                lang="json"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </DocumentationLayout>
</template>
