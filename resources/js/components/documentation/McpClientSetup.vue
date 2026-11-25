<script setup lang="ts">
import { computed } from 'vue';

import CodeBlock from '@/components/documentation/CodeBlock.vue';

const props = defineProps<{ mcpUrl: string }>();

const clientConfig = computed(
    () => `{
  "mcpServers": {
    "byomailer": {
      "type": "http",
      "url": "${props.mcpUrl}",
      "headers": {
        "Authorization": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}`,
);

const claudeDesktopConfig = computed(
    () => `{
  "mcpServers": {
    "byomailer": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "${props.mcpUrl}",
        "--header",
        "Authorization:\${BYOMAILER_AUTH}"
      ],
      "env": {
        "BYOMAILER_AUTH": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}`,
);

const claudeCodeCommand = computed(
    () => `claude mcp add byomailer \\
  --transport http ${props.mcpUrl} \\
  --header "Authorization: Bearer YOUR_API_TOKEN"`,
);

const codexCommand = computed(
    () => `codex mcp add byomailer \\
  --transport http \\
  --url ${props.mcpUrl} \\
  --header "Authorization: Bearer YOUR_API_TOKEN"`,
);
</script>

<template>
    <section class="mb-12">
        <h2 class="mb-4 text-2xl font-bold">Connect a Client</h2>

        <h3 class="mb-2 text-lg font-semibold">Command-line clients</h3>
        <p class="mb-4 text-muted-foreground">
            If your client has a CLI, register the server in one command. For
            <strong>Claude Code</strong>:
        </p>
        <CodeBlock title="Claude Code" :code="claudeCodeCommand" lang="bash" />
        <p class="mt-6 mb-4 text-muted-foreground">
            For the <strong>Codex CLI</strong> and other tools that accept a
            streamable-HTTP server with custom headers:
        </p>
        <CodeBlock title="Codex CLI" :code="codexCommand" lang="bash" />
        <p class="mt-4 mb-8 text-sm text-muted-foreground">
            Any client that speaks streamable HTTP and lets you set an
            <code class="text-sm">Authorization</code> header will work — point
            it at <code class="text-sm">{{ mcpUrl }}</code> with
            <code class="text-sm">Bearer YOUR_API_TOKEN</code>. Run the client's
            <code class="text-sm">mcp list</code> equivalent afterwards to
            confirm the tools are discovered.
        </p>

        <h3 class="mb-2 text-lg font-semibold">Config-file clients</h3>
        <p class="mb-4 text-muted-foreground">
            For <strong>Cursor</strong> and other clients that accept a
            streamable-HTTP server in a JSON file, add BYOMailer to the
            <code class="text-sm">mcpServers</code> block:
        </p>
        <CodeBlock title="mcp.json" :code="clientConfig" lang="json" />

        <h3 class="mt-8 mb-2 text-lg font-semibold">Claude Desktop</h3>
        <p class="mb-4 text-muted-foreground">
            The configuration file of Claude Desktop starts only local commands.
            Use the <code class="text-sm">mcp-remote</code> bridge, which needs
            Node.js. Add this entry to
            <code class="text-sm">claude_desktop_config.json</code>, then start
            Claude Desktop again. The token is in
            <code class="text-sm">env</code>, because Claude Desktop on Windows
            splits an argument at each space.
        </p>
        <CodeBlock
            title="claude_desktop_config.json"
            :code="claudeDesktopConfig"
            lang="json"
        />

        <h3 class="mt-8 mb-2 text-lg font-semibold">Claude.ai</h3>
        <p class="text-muted-foreground">
            Claude.ai is not supported. Its custom connectors sign in with
            OAuth, and the BYOMailer MCP server accepts only an API token in the
            <code class="text-sm">Authorization</code> header. Use Claude Code
            or another client from this page.
        </p>
    </section>
</template>
