import type { McpTool } from '@/types/mcp';

import { appliedField, previewArgument } from './deletePreviewFields';

const projectCountFields = [
    {
        name: 'newsletters_count',
        type: 'integer',
        description: 'Newsletters in the project.',
    },
    {
        name: 'sequences_count',
        type: 'integer',
        description: 'Sequences in the project.',
    },
    {
        name: 'automations_count',
        type: 'integer',
        description: 'Automations in the project.',
    },
    {
        name: 'visual_automations_count',
        type: 'integer',
        description: 'Visual automations in the project.',
    },
];

/** The MCP tools for projects. */
export const projectTools: McpTool[] = [
    {
        id: 'tool-list-projects',
        name: 'list-projects',
        description:
            'List the projects of the account, newest first. A project groups newsletters, sequences and automations. The list has no pagination.',
        arguments: [],
        returns: [
            {
                name: 'projects',
                type: 'array',
                description:
                    'Each project: id, name and the number of records in it.',
                children: projectCountFields,
            },
            {
                name: 'count',
                type: 'integer',
                description: 'The number of projects.',
            },
        ],
        exampleResult: {
            projects: [
                {
                    id: 2,
                    name: 'Spring launch',
                    newsletters_count: 3,
                    sequences_count: 1,
                    automations_count: 2,
                    visual_automations_count: 0,
                },
            ],
            count: 1,
        },
    },
    {
        id: 'tool-create-project',
        name: 'create-project',
        description:
            'Create a project. Project names are unique per account, and the comparison ignores the letter case.',
        arguments: [
            {
                name: 'name',
                type: 'string',
                required: true,
                description:
                    'The project name. It must be unique in the account.',
            },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'New project id.' },
            { name: 'name', type: 'string', description: 'The project name.' },
            { name: 'message', type: 'string', description: 'What happened.' },
        ],
        exampleResult: {
            id: 2,
            name: 'Spring launch',
            message: 'Project #2 created.',
        },
    },
    {
        id: 'tool-update-project',
        name: 'update-project',
        description:
            'Rename a project. A name that another project of the account has returns an error.',
        arguments: [
            {
                name: 'project_id',
                type: 'integer',
                required: true,
                description: 'The project. Find ids with list-projects.',
            },
            {
                name: 'name',
                type: 'string',
                required: true,
                description: 'The new name. It must be unique in the account.',
            },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'The project id.' },
            { name: 'name', type: 'string', description: 'The new name.' },
            { name: 'message', type: 'string', description: 'What happened.' },
        ],
        exampleResult: {
            id: 2,
            name: 'Summer launch',
            message: 'Project #2 renamed.',
        },
    },
    {
        id: 'tool-delete-project',
        name: 'delete-project',
        description:
            'Delete a project. The newsletters, sequences and automations in it stay, but they are no longer in the project. The first call saves nothing and returns a preview with the number of records in the project. Call it again with confirm_live set to true after the user confirms.',
        arguments: [
            {
                name: 'project_id',
                type: 'integer',
                required: true,
                description: 'The project. Find ids with list-projects.',
            },
            previewArgument,
        ],
        returns: [
            ...projectCountFields,
            appliedField,
            {
                name: 'effects',
                type: 'array',
                description:
                    'Only in a preview: what the delete does to the records in the project.',
            },
            {
                name: 'message',
                type: 'string',
                description: 'What happened, or the next step.',
            },
        ],
        exampleResult: {
            id: 2,
            name: 'Spring launch',
            newsletters_count: 3,
            sequences_count: 1,
            automations_count: 2,
            visual_automations_count: 0,
            change: 'delete',
            applied: false,
            message: 'Preview only. No change was saved.',
        },
    },
];
