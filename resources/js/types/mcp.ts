import type { Field } from '@/components/documentation/FieldList.vue';

/** One tool entry on the MCP server documentation page. */
export interface McpTool {
    id: string;
    name: string;
    description: string;
    arguments: Field[];
    returns: Field[];
    exampleResult: Record<string, unknown>;
}
