import type { Field } from '@/components/documentation/FieldList.vue';
import type { McpTool } from '@/types/mcp';

const replyToFields: Field[] = [
    { name: 'id', type: 'integer', description: 'Identity id.' },
    { name: 'email', type: 'string', description: 'Sender email address.' },
    { name: 'name', type: 'string|null', description: 'Sender display name.' },
    {
        name: 'domain',
        type: 'string',
        description: 'Domain registered in SES.',
    },
    {
        name: 'identity_status',
        type: 'string',
        description: 'pending, verifying, verified or failed.',
    },
    { name: 'dkim_status', type: 'string', description: 'PENDING or SUCCESS.' },
    {
        name: 'mail_from_status',
        type: 'string',
        description: 'PENDING or SUCCESS.',
    },
    {
        name: 'open_tracking_enabled',
        type: 'boolean',
        description: 'Whether opens are tracked.',
    },
    {
        name: 'click_tracking_enabled',
        type: 'boolean',
        description: 'Whether clicks are tracked.',
    },
    {
        name: 'verified_at',
        type: 'string|null',
        description: 'ISO-8601 timestamp of verification.',
    },
    {
        name: 'last_status_check_at',
        type: 'string|null',
        description: 'ISO-8601 timestamp of the last AWS status check.',
    },
    {
        name: 'reply_to_email',
        type: 'string|null',
        description: 'The Reply-To email address.',
    },
    {
        name: 'reply_to_name',
        type: 'string|null',
        description: 'The Reply-To display name.',
    },
    {
        name: 'message',
        type: 'string',
        description: 'Human-readable confirmation.',
    },
];

const linkDomainFields: Field[] = [
    { name: 'id', type: 'integer', description: 'Identity id.' },
    { name: 'email', type: 'string', description: 'Sender email address.' },
    {
        name: 'custom_link_domain',
        type: 'string|null',
        description: 'The custom link domain, or null when none is set.',
    },
    {
        name: 'custom_link_domain_verified',
        type: 'boolean',
        description:
            'Whether the domain is verified. Links use the domain only after it is verified.',
    },
    {
        name: 'dns_records',
        type: 'array',
        description:
            'The CNAME record to publish: type, name, value, priority, purpose (link_domain) and verified. Empty when no domain is set.',
    },
];

const linkDomainRecord = {
    type: 'CNAME',
    name: 'links.example.com',
    value: 'links.byomailer.com',
    priority: null,
    purpose: 'link_domain',
    verified: false,
};

const linkDomainExample = {
    id: 3,
    email: 'hello@example.com',
    custom_link_domain: 'links.example.com',
    custom_link_domain_verified: false,
    dns_records: [linkDomainRecord],
};

const templateSummaryFields: Field[] = [
    { name: 'id', type: 'integer', description: 'Template id.' },
    { name: 'name', type: 'string', description: 'Template name.' },
    {
        name: 'is_default',
        type: 'boolean',
        description:
            'Whether new newsletters and steps use this template by default.',
    },
    {
        name: 'archived_at',
        type: 'string|null',
        description: 'ISO-8601 time of archiving, or null.',
    },
    {
        name: 'created_at',
        type: 'string|null',
        description: 'ISO-8601 creation time.',
    },
    {
        name: 'updated_at',
        type: 'string|null',
        description: 'ISO-8601 time of the last change.',
    },
];

const templateSummaryExample = {
    id: 7,
    name: 'Brand layout',
    is_default: true,
    archived_at: null,
    created_at: '2026-11-01T10:00:00+00:00',
    updated_at: '2026-11-20T09:30:00+00:00',
};

const liveUsesField: Field = {
    name: 'live_uses',
    type: 'array',
    description:
        'The scheduled or sending newsletters and the steps of live sequences that use the template: type (newsletter or sequence_step), id, name, status and sequence_id.',
};

const liveUseExample = {
    type: 'newsletter',
    id: 42,
    name: 'Friday issue',
    status: 'scheduled',
    sequence_id: null,
};

export const identityTools: McpTool[] = [
    {
        id: 'tool-update-email-identity-reply-to',
        name: 'update-email-identity-reply-to',
        description:
            'Set or clear the Reply-To address of an identity. Replies to mail from this identity go to this address. Send reply_to_email as null or an empty string to clear the address and the name.',
        arguments: [
            {
                name: 'email_identity_id',
                type: 'integer',
                required: true,
                description: 'The identity id.',
            },
            {
                name: 'reply_to_email',
                type: 'string|null',
                required: true,
                description:
                    'The Reply-To email address. Null or an empty string clears it.',
            },
            {
                name: 'reply_to_name',
                type: 'string|null',
                description:
                    'Optional Reply-To display name. It needs reply_to_email.',
            },
        ],
        returns: replyToFields,
        exampleResult: {
            id: 3,
            email: 'hello@example.com',
            name: 'Example Team',
            domain: 'example.com',
            identity_status: 'verified',
            dkim_status: 'SUCCESS',
            mail_from_status: 'SUCCESS',
            open_tracking_enabled: true,
            click_tracking_enabled: true,
            reply_to_email: 'support@example.com',
            reply_to_name: 'Support Team',
            verified_at: '2026-09-19T10:05:00+00:00',
            last_status_check_at: '2026-09-19T10:05:00+00:00',
            message: 'Reply-To set to support@example.com for identity #3.',
        },
    },
    {
        id: 'tool-update-email-identity-link-domain',
        name: 'update-email-identity-link-domain',
        description:
            'Set or clear the custom link domain of an identity. The unsubscribe, preference and opt-in links in mail from this identity then use this domain. After you set a domain, publish the CNAME record in dns_records, then call check-email-identity-link-domain. Send custom_link_domain as null or an empty string to clear it.',
        arguments: [
            {
                name: 'email_identity_id',
                type: 'integer',
                required: true,
                description: 'The identity id.',
            },
            {
                name: 'custom_link_domain',
                type: 'string|null',
                required: true,
                description:
                    'A hostname that you control, for example links.example.com. Null or an empty string clears it. A domain of another account is refused.',
            },
        ],
        returns: [
            ...linkDomainFields,
            {
                name: 'message',
                type: 'string',
                description: 'Human-readable confirmation with the next step.',
            },
        ],
        exampleResult: {
            ...linkDomainExample,
            message:
                'Custom link domain links.example.com saved for identity #3. Publish the CNAME record, then call check-email-identity-link-domain-tool.',
        },
    },
    {
        id: 'tool-check-email-identity-link-domain',
        name: 'check-email-identity-link-domain',
        description:
            'Check the DNS and the TLS certificate of the custom link domain of an identity, and mark the domain verified when both are live. A pending result stays cached for 30 seconds. A verified result stays cached for 5 minutes.',
        arguments: [
            {
                name: 'email_identity_id',
                type: 'integer',
                required: true,
                description: 'The identity id.',
            },
        ],
        returns: [
            ...linkDomainFields,
            {
                name: 'checked_at',
                type: 'string',
                description: 'ISO-8601 time of the check.',
            },
            {
                name: 'message',
                type: 'string',
                description: 'Human-readable status line.',
            },
        ],
        exampleResult: {
            ...linkDomainExample,
            custom_link_domain_verified: true,
            dns_records: [{ ...linkDomainRecord, verified: true }],
            checked_at: '2026-11-26T10:00:00+00:00',
            message:
                'links.example.com is verified. Links in mail from identity #3 use it.',
        },
    },
    {
        id: 'tool-list-email-templates',
        name: 'list-email-templates',
        description:
            'List the email templates of the account, newest first, without the HTML. A template is the HTML layout around the content of a newsletter or a sequence step. Archived templates are left out unless archived is true.',
        arguments: [
            {
                name: 'archived',
                type: 'boolean',
                description:
                    'Set to true to list only the archived templates. Default false.',
            },
            {
                name: 'is_default',
                type: 'boolean',
                description:
                    'Optional filter: true for the default template only, false for the other templates.',
            },
            {
                name: 'search',
                type: 'string',
                description: 'Optional search against the template name.',
            },
            {
                name: 'limit',
                type: 'integer',
                description: 'Maximum templates to return (1-100, default 25).',
            },
        ],
        returns: [
            {
                name: 'templates',
                type: 'array',
                description:
                    'Template summaries: id, name, is_default, archived_at, created_at and updated_at.',
            },
            {
                name: 'count',
                type: 'integer',
                description: 'Number of templates returned.',
            },
        ],
        exampleResult: {
            templates: [templateSummaryExample],
            count: 1,
        },
    },
    {
        id: 'tool-email-template-content',
        name: 'email-template-content',
        description:
            'Show one email template with its HTML and the live records that use it. A change to the template reaches the contacts of these records.',
        arguments: [
            {
                name: 'email_template_id',
                type: 'integer',
                required: true,
                description: 'The template id from list-email-templates.',
            },
        ],
        returns: [
            ...templateSummaryFields,
            {
                name: 'html_content',
                type: 'string',
                description:
                    'The full HTML, with one {{ content }} placeholder.',
            },
            liveUsesField,
        ],
        exampleResult: {
            ...templateSummaryExample,
            html_content:
                '<table><tr><td>{{ content }}</td></tr></table><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            live_uses: [liveUseExample],
        },
    },
    {
        id: 'tool-create-email-template',
        name: 'create-email-template',
        description:
            'Create an email template. The HTML must have exactly one {{ content }} placeholder and an unsubscribe link. The first template of an account becomes the default.',
        arguments: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'Template name.',
            },
            {
                name: 'html_content',
                type: 'string',
                required: true,
                description:
                    'The full HTML, with one {{ content }} placeholder. Maximum 500 KB.',
            },
            {
                name: 'confirmed_no_unsubscribe',
                type: 'boolean',
                description:
                    'Set to true only after the user confirms to save HTML without an href="{{ unsubscribe_url }}" link.',
            },
        ],
        returns: [
            {
                name: 'id',
                type: 'integer',
                description: 'The new template id.',
            },
            { name: 'name', type: 'string', description: 'Template name.' },
            {
                name: 'is_default',
                type: 'boolean',
                description: 'Whether the template became the default.',
            },
            {
                name: 'message',
                type: 'string',
                description: 'Human-readable confirmation.',
            },
        ],
        exampleResult: {
            id: 7,
            name: 'Brand layout',
            is_default: true,
            message: 'Email template #7 created.',
        },
    },
    {
        id: 'tool-update-email-template',
        name: 'update-email-template',
        description:
            'Update the name or the HTML of an email template. Only the fields that you send change. Messages that are not sent yet use the new HTML. If a live record uses the template, the first call saves nothing and returns a preview. After the user confirms, call the tool again with the same arguments and confirm_live set to true.',
        arguments: [
            {
                name: 'email_template_id',
                type: 'integer',
                required: true,
                description: 'The template id.',
            },
            {
                name: 'name',
                type: 'string',
                description: 'The new template name.',
            },
            {
                name: 'html_content',
                type: 'string',
                description:
                    'The new full HTML, with one {{ content }} placeholder. Maximum 500 KB.',
            },
            {
                name: 'confirmed_no_unsubscribe',
                type: 'boolean',
                description:
                    'Set to true only after the user confirms to save HTML without an unsubscribe link.',
            },
            {
                name: 'confirm_live',
                type: 'boolean',
                description:
                    'Set to true only after the user confirms the change to a template that live records use.',
            },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'Template id.' },
            {
                name: 'name',
                type: 'string',
                description: 'Template name before the change.',
            },
            {
                name: 'changes',
                type: 'object',
                description:
                    'Only in a preview: the name change (from, to) and html_content (replaced or unchanged).',
            },
            liveUsesField,
            {
                name: 'impact',
                type: 'string',
                description:
                    'Only in a preview: what the change does to contacts.',
            },
            {
                name: 'applied',
                type: 'boolean',
                description:
                    'False for a preview. True when the change is saved.',
            },
            {
                name: 'message',
                type: 'string',
                description: 'What happened, or the next step.',
            },
        ],
        exampleResult: {
            id: 7,
            name: 'Brand layout',
            changes: { html_content: 'replaced' },
            live_uses: [liveUseExample],
            impact: 'The live records in live_uses send with this template. Their contacts receive the new version.',
            applied: false,
            message:
                'Preview only. No change was saved. Show this preview to the user, ask the user to confirm, then call this tool again with the same arguments and confirm_live set to true.',
        },
    },
];
