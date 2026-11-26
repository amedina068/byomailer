import type { Field } from '@/components/documentation/FieldList.vue';

interface McpToolDoc {
    id: string;
    name: string;
    description: string;
    arguments: Field[];
    returns: Field[];
    exampleResult: Record<string, unknown>;
}

const listFields: Field[] = [
    { name: 'id', type: 'integer', description: 'List id.' },
    { name: 'name', type: 'string', description: 'List name.' },
    {
        name: 'opt_in_enabled',
        type: 'boolean',
        description:
            'true when a contact must confirm the opt-in email before the contact counts on the list.',
    },
    {
        name: 'opt_in_email',
        type: 'object|null',
        description:
            'The subject and the email_identity_id of the opt-in email, or null when the list has no opt-in email.',
    },
];

const topicFields: Field[] = [
    { name: 'id', type: 'integer', description: 'Topic id.' },
    { name: 'list_id', type: 'integer', description: 'Id of the list.' },
    { name: 'name', type: 'string', description: 'Topic name.' },
    {
        name: 'description',
        type: 'string|null',
        description: 'Text that contacts see on the preference page.',
    },
];

const confirmLiveArgument: Field = {
    name: 'confirm_live',
    type: 'boolean',
    description:
        'Leave it out to get a preview. Set it to true only after the user confirms the preview.',
};

const previewReturns: Field[] = [
    {
        name: 'applied',
        type: 'boolean',
        description: 'false for a preview. true when the change is saved.',
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
];

const listExample = {
    id: 3,
    name: 'Customers',
    opt_in_enabled: false,
    opt_in_email: null,
};

const topicExample = {
    id: 7,
    list_id: 3,
    name: 'Product news',
    description: 'New features and changes.',
};

export const listTools: McpToolDoc[] = [
    {
        id: 'tool-list-lists',
        name: 'list-lists',
        description:
            'List the mailing lists of the account, newest first, with the opt-in setting, the contact counts and the topics of each list. On a list with opt-in, only the contacts that confirmed the opt-in email count for audiences.',
        arguments: [
            {
                name: 'search',
                type: 'string',
                description:
                    'Search in the list name. The search ignores case.',
            },
            {
                name: 'limit',
                type: 'integer',
                description: 'Maximum number of lists, 1-100 (default 25).',
            },
        ],
        returns: [
            {
                name: 'lists',
                type: 'array',
                description: 'The lists.',
                children: [
                    ...listFields,
                    {
                        name: 'contacts_count',
                        type: 'integer',
                        description: 'All contacts on the list.',
                    },
                    {
                        name: 'confirmed_contacts_count',
                        type: 'integer',
                        description:
                            'Contacts that newsletters, sequences and automations select through the list.',
                    },
                    {
                        name: 'topics',
                        type: 'array',
                        description:
                            'The topics of the list, each with subscribed_count.',
                        children: topicFields,
                    },
                ],
            },
            {
                name: 'count',
                type: 'integer',
                description: 'Number of lists returned.',
            },
        ],
        exampleResult: {
            lists: [
                {
                    ...listExample,
                    contacts_count: 1250,
                    confirmed_contacts_count: 1250,
                    topics: [{ ...topicExample, subscribed_count: 1180 }],
                },
            ],
            count: 1,
        },
    },
    {
        id: 'tool-create-list',
        name: 'create-list',
        description:
            'Create a mailing list. A list name is unique in the account. A new list has no opt-in and no topics. Use update-list to turn on opt-in and create-topic to add topics.',
        arguments: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The list name. It must be unique in the account.',
            },
        ],
        returns: [
            ...listFields,
            {
                name: 'message',
                type: 'string',
                description: 'Confirmation text.',
            },
        ],
        exampleResult: { ...listExample, message: 'List #3 created.' },
    },
    {
        id: 'tool-update-list',
        name: 'update-list',
        description:
            'Change the name and the opt-in settings of a list, with the same rules as the dashboard. Only the fields that you pass change. A change of opt_in_enabled changes which contacts get the mail that selects the list. The first call saves nothing and returns a preview with the contacts that did not confirm and the items that select the list. Call it again with confirm_live set to true after the user confirms. A change of the name or of the opt-in email needs no confirmation.',
        arguments: [
            {
                name: 'list_id',
                type: 'integer',
                required: true,
                description: 'The list. Find ids with list-lists.',
            },
            {
                name: 'name',
                type: 'string',
                description: 'New list name. It must be unique in the account.',
            },
            {
                name: 'opt_in_enabled',
                type: 'boolean',
                description:
                    'true turns on opt-in, false turns it off. A change needs the preview and confirm_live.',
            },
            {
                name: 'opt_in_subject',
                type: 'string',
                description:
                    'Subject of the opt-in email. Necessary when opt-in is on.',
            },
            {
                name: 'opt_in_html_content',
                type: 'string',
                description:
                    'HTML body of the opt-in email, with the {{ opt_in_url }} link. Necessary when opt-in is on.',
            },
            {
                name: 'opt_in_email_identity_id',
                type: 'integer',
                description:
                    'Sender identity of the opt-in email. Find ids with list-email-identities. Necessary when opt-in is on.',
            },
            confirmLiveArgument,
        ],
        returns: [
            ...listFields,
            {
                name: 'unconfirmed_contacts',
                type: 'integer',
                description:
                    'Only in a preview: contacts on the list that did not confirm an opt-in email.',
            },
            {
                name: 'used_by',
                type: 'object',
                description:
                    'Only in a preview: the newsletters, sequences, segments and automations that select the list or one of its topics.',
            },
            ...previewReturns,
        ],
        exampleResult: {
            ...listExample,
            change: 'enable_opt_in',
            new_opt_in_enabled: true,
            contacts_count: 1250,
            unconfirmed_contacts: 1250,
            applied: false,
            message: 'Preview only. No change was saved.',
        },
    },
    {
        id: 'tool-create-topic',
        name: 'create-topic',
        description:
            'Create a topic in a list. A contact can unsubscribe from one topic on the preference page and stay on the list. A topic name is unique in its list. Each contact that counts on the list is subscribed to the new topic in the background.',
        arguments: [
            {
                name: 'list_id',
                type: 'integer',
                required: true,
                description: 'The list. Find ids with list-lists.',
            },
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The topic name. It must be unique in the list.',
            },
            {
                name: 'description',
                type: 'string',
                description:
                    'Text for the preference page. Maximum 1000 characters.',
            },
        ],
        returns: [
            ...topicFields,
            {
                name: 'message',
                type: 'string',
                description: 'Confirmation text.',
            },
        ],
        exampleResult: {
            ...topicExample,
            message: 'Topic #7 created in list #3.',
        },
    },
    {
        id: 'tool-update-topic',
        name: 'update-topic',
        description:
            'Change the name or the description of a topic. Only the fields that you pass change. The subscriptions of the contacts do not change.',
        arguments: [
            {
                name: 'topic_id',
                type: 'integer',
                required: true,
                description: 'The topic. Find ids with list-lists.',
            },
            {
                name: 'name',
                type: 'string',
                description: 'New topic name. It must be unique in the list.',
            },
            {
                name: 'description',
                type: 'string|null',
                description:
                    'New text for the preference page. Send null to clear it.',
            },
        ],
        returns: [
            ...topicFields,
            {
                name: 'message',
                type: 'string',
                description: 'Confirmation text.',
            },
        ],
        exampleResult: { ...topicExample, message: 'Topic #7 updated.' },
    },
    {
        id: 'tool-delete-topic',
        name: 'delete-topic',
        description:
            'Delete a topic and the topic subscriptions of its contacts. The contacts stay on the list. The first call saves nothing and returns a preview with the subscribed and the unsubscribed contacts. Call it again with confirm_live set to true after the user confirms. When newsletters, sequences, segments or automations use the topic, the tool refuses the delete and names them.',
        arguments: [
            {
                name: 'topic_id',
                type: 'integer',
                required: true,
                description: 'The topic. Find ids with list-lists.',
            },
            confirmLiveArgument,
        ],
        returns: [
            ...topicFields,
            {
                name: 'subscribed_contacts',
                type: 'integer',
                description:
                    'Only in a preview: contacts that lose their subscription to the topic.',
            },
            {
                name: 'unsubscribed_contacts',
                type: 'integer',
                description:
                    'Only in a preview: contacts that unsubscribed from the topic. Their choice is deleted.',
            },
            ...previewReturns,
        ],
        exampleResult: {
            ...topicExample,
            change: 'delete',
            subscribed_contacts: 1180,
            unsubscribed_contacts: 70,
            applied: false,
            message: 'Preview only. No change was saved.',
        },
    },
];
