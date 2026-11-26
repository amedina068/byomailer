import type { McpTool } from '@/types/mcp';

import { appliedField, previewArgument } from './deletePreviewFields';

/** The MCP tools for tags. */
export const tagTools: McpTool[] = [
    {
        id: 'tool-create-tag',
        name: 'create-tag',
        description:
            'Create a tag for the account. Tags label contacts and build audiences. Tag names are unique per account — creating a name that already exists returns an error.',
        arguments: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The tag name. Must be unique within the account.',
            },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'New tag id.' },
            { name: 'name', type: 'string', description: 'The tag name.' },
            {
                name: 'message',
                type: 'string',
                description: 'Human-readable confirmation.',
            },
        ],
        exampleResult: {
            id: 9,
            name: 'VIP',
            message: 'Tag #9 created.',
        },
    },
    {
        id: 'tool-list-tags',
        name: 'list-tags',
        description:
            'List the tags of the account, newest first, with the number of contacts that have each tag. The list has no pagination.',
        arguments: [],
        returns: [
            {
                name: 'tags',
                type: 'array',
                description: 'Each tag: id, name and contacts_count.',
            },
            {
                name: 'count',
                type: 'integer',
                description: 'The number of tags.',
            },
        ],
        exampleResult: {
            tags: [
                { id: 9, name: 'VIP', contacts_count: 412 },
                { id: 4, name: 'Trial', contacts_count: 88 },
            ],
            count: 2,
        },
    },
    {
        id: 'tool-rename-tag',
        name: 'rename-tag',
        description:
            'Rename a tag. A name that another tag of the account has returns an error. Tag filters select tags by name. If a saved filter of a newsletter that can still send, a sequence, a sequence step or a segment uses the tag, the rename is refused.',
        arguments: [
            {
                name: 'tag_id',
                type: 'integer',
                required: true,
                description: 'The tag. Find ids with list-tags.',
            },
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The new name. It must be unique in the account.',
            },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'The tag id.' },
            { name: 'name', type: 'string', description: 'The new name.' },
            {
                name: 'previous_name',
                type: 'string',
                description: 'The name before the rename.',
            },
            { name: 'message', type: 'string', description: 'What happened.' },
        ],
        exampleResult: {
            id: 9,
            name: 'Gold',
            previous_name: 'VIP',
            message: 'Tag #9 renamed.',
        },
    },
    {
        id: 'tool-delete-tag',
        name: 'delete-tag',
        description:
            'Delete a tag and remove it from each contact. The removal runs the live automations that start when a contact loses the tag. The first call saves nothing and returns a preview with the contacts that have the tag and these automations. Call it again with confirm_live set to true after the user confirms. If a saved filter uses the tag, the delete is refused, as in the dashboard.',
        arguments: [
            {
                name: 'tag_id',
                type: 'integer',
                required: true,
                description: 'The tag. Find ids with list-tags.',
            },
            previewArgument,
        ],
        returns: [
            {
                name: 'contacts_count',
                type: 'integer',
                description: 'The contacts that have the tag.',
            },
            {
                name: 'live_automation_ids',
                type: 'array',
                description:
                    'Only in a preview: live automations that run when a contact loses the tag.',
            },
            {
                name: 'live_visual_automation_ids',
                type: 'array',
                description:
                    'Only in a preview: live visual automations that enroll a contact that loses the tag.',
            },
            appliedField,
            {
                name: 'effects',
                type: 'array',
                description:
                    'Only in a preview: what the delete does to contacts and automations.',
            },
            {
                name: 'message',
                type: 'string',
                description: 'What happened, or the next step.',
            },
        ],
        exampleResult: {
            id: 9,
            name: 'VIP',
            contacts_count: 412,
            change: 'delete',
            live_automation_ids: [12],
            live_visual_automation_ids: [],
            applied: false,
            message: 'Preview only. No change was saved.',
        },
    },
];
