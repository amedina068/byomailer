import type { Field } from '@/components/documentation/FieldList.vue';
import type { McpTool } from '@/types/mcp';

const accountProfileFields: Field[] = [
    {
        name: 'user_name',
        type: 'string',
        description: 'The name of the authenticated user.',
    },
    {
        name: 'timezone',
        type: 'string',
        description: 'The timezone of the account.',
    },
    {
        name: 'postal_address',
        type: 'string|null',
        description: 'The postal address that {{ postal_address }} shows.',
    },
    {
        name: 'has_postal_address',
        type: 'boolean',
        description:
            'false when the address is empty. Then each schedule and activation preview shows a warning.',
    },
    {
        name: 'sunset_inactive_days',
        type: 'integer|null',
        description: 'The sunset rule in days. null when the rule is off.',
    },
    {
        name: 'can_edit',
        type: 'boolean',
        description:
            'true when this user can change the settings with update-account-profile.',
    },
];

const accountProfileExample = {
    user_name: 'Ada Lovelace',
    timezone: 'America/Chicago',
    postal_address: '1 Main St, Springfield, IL 62701, USA',
    has_postal_address: true,
    sunset_inactive_days: 180,
    can_edit: true,
};

export const accountProfileTools: McpTool[] = [
    {
        id: 'tool-view-account-profile',
        name: 'view-account-profile',
        description:
            'Read the account profile: the postal address, the timezone and the sunset rule of the account, and the name of the authenticated user. The postal address, the timezone and the sunset rule apply to the whole team. A Viewer can also use this tool.',
        arguments: [
            {
                name: 'include_sunset_preview',
                type: 'boolean',
                description:
                    'Set to true to add sunset_preview. The count scans the contacts, so it is off by default.',
            },
        ],
        returns: [
            ...accountProfileFields,
            {
                name: 'sunset_preview',
                type: 'object|null',
                description:
                    'Only when include_sunset_preview is true: count (the contacts that the next daily sunset run stops mailing) and days. null when the sunset rule is off.',
            },
        ],
        exampleResult: {
            ...accountProfileExample,
            sunset_preview: { count: 42, days: 180 },
        },
    },
    {
        id: 'tool-update-account-profile',
        name: 'update-account-profile',
        description:
            'Change the postal address, the timezone or the sunset rule of the account. Send only the fields to change. The tool uses the same validation as the Profile page. Only an Admin or an Editor with a write token can use it. It cannot change the name, the email or the password of a user.',
        arguments: [
            {
                name: 'postal_address',
                type: 'string|null',
                description:
                    'The postal address of the sender, up to 500 characters. Templates show it with {{ postal_address }}. null or an empty string removes it.',
            },
            {
                name: 'timezone',
                type: 'string',
                description:
                    'An IANA timezone, for example America/Chicago. BYOMailer uses it to schedule newsletters and automations.',
            },
            {
                name: 'sunset_inactive_days',
                type: 'integer|null',
                description:
                    'Stop mail to contacts who did not click in this number of days, from 30 to 730. null turns the sunset rule off.',
            },
        ],
        returns: [
            ...accountProfileFields,
            {
                name: 'updated',
                type: 'array',
                description: 'The fields that the call sent.',
            },
            {
                name: 'message',
                type: 'string',
                description: 'Human-readable confirmation.',
            },
        ],
        exampleResult: {
            ...accountProfileExample,
            updated: ['postal_address'],
            message: 'Account settings updated: postal_address.',
        },
    },
];
