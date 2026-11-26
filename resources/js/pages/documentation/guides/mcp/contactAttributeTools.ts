import type { McpTool } from '@/types/mcp';

/** The MCP tools for the default values of contact attribute keys. */
export const contactAttributeTools: McpTool[] = [
    {
        id: 'tool-list-contact-attributes',
        name: 'list-contact-attributes',
        description:
            'List the custom contact attribute keys of the account, sorted by name, with the default value of each key. A {{ variable }} placeholder with the same name shows the default value when a contact has no value for the key.',
        arguments: [],
        returns: [
            {
                name: 'attributes',
                type: 'array',
                description:
                    'Each key: id, name and default_value. default_value is null when the key has no default.',
            },
            {
                name: 'count',
                type: 'integer',
                description: 'The number of keys.',
            },
        ],
        exampleResult: {
            attributes: [
                { id: 3, name: 'city', default_value: null },
                { id: 5, name: 'nickname', default_value: 'friend' },
            ],
            count: 2,
        },
    },
    {
        id: 'tool-update-contact-attribute-default',
        name: 'update-contact-attribute-default',
        description:
            'Set or clear the default value of one contact attribute key. The values of the contacts do not change.',
        arguments: [
            {
                name: 'attribute_id',
                type: 'integer',
                required: true,
                description:
                    'The attribute key. Find ids with list-contact-attributes.',
            },
            {
                name: 'default_value',
                type: 'string',
                required: true,
                description:
                    'The new default value, at most 255 characters. Send null or an empty string to clear the default.',
            },
        ],
        returns: [
            { name: 'id', type: 'integer', description: 'The attribute id.' },
            { name: 'name', type: 'string', description: 'The attribute key.' },
            {
                name: 'default_value',
                type: 'string',
                description: 'The saved default value, or null.',
            },
            { name: 'message', type: 'string', description: 'What happened.' },
        ],
        exampleResult: {
            id: 5,
            name: 'nickname',
            default_value: 'friend',
            message: 'The default value of attribute "nickname" is saved.',
        },
    },
];
