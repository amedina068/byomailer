import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ template: '<span />' }),
    Link: defineComponent({ template: '<a><slot /></a>' }),
    usePage: () => ({ props: { appUrl: 'https://byomailer.example' } }),
}));
vi.mock('@/layouts/documentation/DocumentationLayout.vue', () => ({
    default: defineComponent({ template: '<main><slot /></main>' }),
}));

import McpServer from '../McpServer.vue';

describe('MCP server guide', () => {
    it('says that Claude.ai cannot connect, because it needs OAuth', () => {
        const text = mount(McpServer, {
            global: { stubs: { CodeBlock: true } },
        }).text();

        expect(text).toContain('Claude.ai is not supported.');
        expect(text).toContain('only an API token');
    });

    it('gives Claude Desktop a local bridge, because its config file runs only local commands', () => {
        const blocks = mount(McpServer, {
            global: { stubs: { CodeBlock: true } },
        }).findAllComponents({ name: 'CodeBlock' });

        const desktop = blocks.find(
            (block) => block.props('title') === 'claude_desktop_config.json',
        );
        const server = JSON.parse(desktop?.props('code')).mcpServers.byomailer;

        expect(server.command).toBe('npx');
        expect(server.args).toEqual([
            '-y',
            'mcp-remote',
            'https://byomailer.example/mcp',
            '--header',
            'Authorization:${BYOMAILER_AUTH}',
        ]);
        expect(server.env).toEqual({ BYOMAILER_AUTH: 'Bearer YOUR_API_TOKEN' });
    });
});
