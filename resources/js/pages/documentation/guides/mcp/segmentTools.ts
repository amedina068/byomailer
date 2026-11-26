import type { Field } from '@/components/documentation/FieldList.vue';

interface McpTool {
    id: string;
    name: string;
    description: string;
    arguments: Field[];
    returns: Field[];
    exampleResult: Record<string, unknown>;
}

const segmentFields: Field[] = [
    { name: 'id', type: 'integer', description: 'Segment id.' },
    {
        name: 'name',
        type: 'string',
        description: 'Segment name. It is unique in the account.',
    },
    {
        name: 'description',
        type: 'string|null',
        description: 'Segment description.',
    },
    {
        name: 'filters',
        type: 'array',
        description:
            'The saved filters, in the view-newsletter-filters format.',
    },
    {
        name: 'archived',
        type: 'boolean',
        description: 'Whether the segment is archived.',
    },
    {
        name: 'archived_at',
        type: 'string|null',
        description: 'ISO 8601 time of the archive.',
    },
    {
        name: 'contacts_count',
        type: 'integer',
        description:
            'Contacts that match the filters. This count includes unsubscribed contacts, the same as the dashboard.',
    },
];

const vipFilters = [
    { connector: 'and', category: 'tags', mode: 'any', values: ['vip'] },
];

const segmentExample = {
    id: 9,
    name: 'VIPs',
    description: 'Contacts with the vip tag',
    filters: vipFilters,
    archived: false,
    archived_at: null,
    contacts_count: 412,
};

const segmentArguments: Field[] = [
    {
        name: 'description',
        type: 'string',
        description: 'Segment description. Maximum 1000 characters.',
    },
    {
        name: 'filters',
        type: 'array',
        description: '1 to 20 filters in the view-newsletter-filters format.',
    },
];

export const segmentTools: McpTool[] = [
    {
        id: 'tool-list-segments',
        name: 'list-segments',
        description:
            'List the saved segments of the account, newest first. A segment is a saved set of filters. Newsletters and sequences can use a segment as their audience. By default the tool lists only the segments that are not archived.',
        arguments: [
            {
                name: 'status',
                type: 'string',
                description: 'active (default), archived or all.',
            },
            {
                name: 'search',
                type: 'string',
                description:
                    'Search on the segment name. Case does not matter.',
            },
            {
                name: 'limit',
                type: 'integer',
                description: 'Maximum rows to return, 1-100 (default 25).',
            },
        ],
        returns: [
            {
                name: 'segments',
                type: 'array',
                description: 'One entry for each segment.',
                children: [
                    ...segmentFields,
                    {
                        name: 'newsletters_count',
                        type: 'integer',
                        description: 'Newsletters that use the segment.',
                    },
                    {
                        name: 'sequences_count',
                        type: 'integer',
                        description: 'Sequences that use the segment.',
                    },
                ],
            },
            {
                name: 'count',
                type: 'integer',
                description: 'Number of rows returned.',
            },
        ],
        exampleResult: {
            segments: [
                { ...segmentExample, newsletters_count: 2, sequences_count: 1 },
            ],
            count: 1,
        },
    },
    {
        id: 'tool-create-segment',
        name: 'create-segment',
        description:
            'Create a saved segment. A duplicate name or an invalid filter returns a validation error and saves nothing. A new segment is not in use, so it sends no mail.',
        arguments: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'Segment name. It must be unique in the account.',
            },
            segmentArguments[0],
            { ...segmentArguments[1], required: true },
        ],
        returns: [
            ...segmentFields,
            { name: 'message', type: 'string', description: 'What happened.' },
        ],
        exampleResult: { ...segmentExample, message: 'Segment #9 created.' },
    },
    {
        id: 'tool-update-segment',
        name: 'update-segment',
        description:
            'Change the name, the description or the filters of a segment. Only the fields that you pass change. New filters replace all the current filters. If the new filters change the audience of a scheduled or sending newsletter or a live sequence, the first call saves nothing and returns a preview. Call the tool again with confirm_live set to true after the user confirms.',
        arguments: [
            {
                name: 'segment_id',
                type: 'integer',
                required: true,
                description: 'The segment. Find ids with list-segments.',
            },
            {
                name: 'name',
                type: 'string',
                description: 'New name. It must be unique in the account.',
            },
            ...segmentArguments,
            {
                name: 'confirm_live',
                type: 'boolean',
                description:
                    'Leave it out to get a preview. Set it to true only after the user confirms the preview.',
            },
        ],
        returns: [
            ...segmentFields,
            {
                name: 'applied',
                type: 'boolean',
                description:
                    'false for a preview. true when the change is saved.',
            },
            {
                name: 'new_filters',
                type: 'array',
                description:
                    'Only in a preview: the filters that the change saves.',
            },
            {
                name: 'new_contacts_count',
                type: 'integer',
                description:
                    'Only in a preview: the contacts that match the new filters.',
            },
            {
                name: 'live_newsletters',
                type: 'array',
                description:
                    'Only in a preview: the scheduled and sending newsletters that use the segment, with id, title, status and scheduled_at.',
            },
            {
                name: 'live_sequences',
                type: 'array',
                description:
                    'Only in a preview: the live sequences that use the segment, with id, name and status.',
            },
            {
                name: 'effects',
                type: 'array',
                description:
                    'Only in a preview: what the change does to contacts and mails.',
            },
            {
                name: 'message',
                type: 'string',
                description: 'What happened, or the next step.',
            },
        ],
        exampleResult: {
            ...segmentExample,
            applied: true,
            message: 'Segment #9 updated.',
        },
    },
    {
        id: 'tool-set-segment-archived',
        name: 'set-segment-archived',
        description:
            'Archive or restore a segment. An archived segment is not in the pickers of the dashboard. Newsletters and sequences that use the segment keep its filters, so this change needs no confirmation.',
        arguments: [
            {
                name: 'segment_id',
                type: 'integer',
                required: true,
                description: 'The segment. Find ids with list-segments.',
            },
            {
                name: 'archived',
                type: 'boolean',
                required: true,
                description: 'true archives the segment. false restores it.',
            },
        ],
        returns: [
            ...segmentFields,
            {
                name: 'applied',
                type: 'boolean',
                description:
                    'false when the segment already had this state. true when the change is saved.',
            },
            { name: 'message', type: 'string', description: 'What happened.' },
        ],
        exampleResult: {
            ...segmentExample,
            archived: true,
            archived_at: '2026-11-26T10:00:00+00:00',
            applied: true,
            message: 'Segment #9 archived.',
        },
    },
];
