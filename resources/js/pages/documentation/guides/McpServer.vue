<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { KeyRound, Plug, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';

import CodeBlock from '@/components/documentation/CodeBlock.vue';
import FieldList from '@/components/documentation/FieldList.vue';
import McpClientSetup from '@/components/documentation/McpClientSetup.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAppUrl } from '@/composables/useAppUrl';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

import { tools } from './mcp/tools';

const { activeSection, sidebarItems } = useDocumentationSidebar('mcp-server');

const callExample = `{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "newsletter-stats",
    "arguments": { "newsletter_id": 42 }
  }
}`;

const appUrl = useAppUrl();
const mcpUrl = computed(() => `${appUrl.value}/mcp`);
const formatJson = (value: Record<string, unknown>): string =>
    JSON.stringify(value, null, 2);
</script>

<template>
    <Head title="MCP Server: Connect AI Clients to Your Email Marketing">
        <meta
            head-key="description"
            name="description"
            content="Connect Claude Code, Claude Desktop, Cursor, and other AI clients to BYOMailer with the Model Context Protocol. Read stats, compose drafts, and confirm each live change."
        />
    </Head>

    <DocumentationLayout
        :active-section="activeSection"
        :sidebar-items="sidebarItems"
    >
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">MCP Server</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Connect AI clients to BYOMailer through the
                <a
                    href="https://modelcontextprotocol.io"
                    target="_blank"
                    rel="noopener"
                    class="underline"
                    >Model Context Protocol</a
                >. An assistant can pull your newsletter and sequence stats and
                content, and help you compose a newsletter — preview the
                audience, save a draft and send yourself a test. It sends to
                your audience only after you confirm each change: the assistant
                first shows a preview of what changes and how many contacts it
                affects.
            </p>
        </div>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">
                Endpoint &amp; Authentication
            </h2>
            <p class="mb-6 text-muted-foreground">
                The server is served over streamable HTTP at
                <code class="text-sm">{{ mcpUrl }}</code> and is authenticated
                with the same API tokens used by the REST API. Generate a token
                in your account settings and send it as a bearer token on every
                request. A read-only token, or a token of a team member with the
                Viewer role, sees only the tools that read data.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <Plug
                                class="h-5 w-5 text-blue-600 dark:text-blue-400"
                            />
                            <CardTitle class="text-base">Transport</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Streamable HTTP. Point any MCP-compatible client at
                            <code class="text-sm">/mcp</code> with an
                            <code class="text-sm">Authorization</code> header.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <KeyRound
                                class="h-5 w-5 text-emerald-600 dark:text-emerald-400"
                            />
                            <CardTitle class="text-base"
                                >Bearer Token</CardTitle
                            >
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Use a personal API token:
                            <code class="text-sm"
                                >Authorization: Bearer YOUR_API_TOKEN</code
                            >. Requests are rate limited.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <ShieldCheck
                                class="h-5 w-5 text-amber-600 dark:text-amber-400"
                            />
                            <CardTitle class="text-base"
                                >No surprise sends</CardTitle
                            >
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            The assistant can read your campaigns and create
                            drafts. Before it schedules a newsletter or turns a
                            sequence, step or automation on or off, it shows you
                            a preview with the contacts it affects and waits for
                            your confirmation.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <McpClientSetup :mcp-url="mcpUrl" />

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Calling a Tool</h2>
            <p class="mb-4 text-muted-foreground">
                Clients invoke tools with a standard MCP
                <code class="text-sm">tools/call</code> request. The
                <code class="text-sm">name</code> is the tool name below and
                <code class="text-sm">arguments</code> matches its input schema.
                Most clients build this request for you — you just describe what
                you want.
            </p>
            <CodeBlock title="tools/call" :code="callExample" lang="json" />
        </section>

        <section class="mb-4">
            <h2 class="mb-2 text-2xl font-bold">Tools</h2>
            <p class="mb-8 text-muted-foreground">
                Seventy-two tools. Start with a list tool to discover ids, then
                read stats or content — use view-newsletter-filters,
                calculate-newsletter-contacts, create-newsletter,
                update-newsletter and send-newsletter-test-mail to compose and
                revise a draft newsletter, or create-sequence,
                create-sequence-step, update-sequence-step and
                send-sequence-step-test-mail to draft, revise and preview a drip
                campaign. create-tag adds a tag for labelling contacts.
                find-contact, create-contact, add-contact-to-list,
                add-tags-to-contact and remove-contact-from-list add people to
                lists and tags. list-contacts lists and filters the audience,
                and bulk-update-contacts changes the tags, custom attributes,
                name and lists of up to 100 contacts in one call.
                list-email-identities, email-identity-details,
                create-email-identity, refresh-email-identity-status and
                update-email-identity-tracking manage the sender identities
                (verified sending domains) in your own AWS SES.
                list-automations, automation-details, view-automation-options,
                create-automation and update-automation manage automations.
                view-account-profile and update-account-profile read and change
                the postal address, the timezone and the sunset rule of the
                account. list-tags, rename-tag and delete-tag manage tags.
                list-contact-attributes and update-contact-attribute-default
                manage the default values of contact attributes. list-projects,
                create-project, update-project and delete-project manage
                projects. list-lists, create-list, update-list, create-topic,
                update-topic and delete-topic manage lists and their topics.
                list-segments, create-segment, update-segment and
                set-segment-archived manage segments.
                update-email-identity-reply-to,
                update-email-identity-link-domain and
                check-email-identity-link-domain change the sender settings of
                an identity. list-email-templates, email-template-content,
                create-email-template and update-email-template manage email
                templates. A delete, and a change to the audience of a live
                record, also need the confirmation of the user. New automations
                are always paused. schedule-newsletter, set-sequence-status,
                set-sequence-step-status and set-automation-status schedule,
                activate and pause records. The update tools can also edit a
                scheduled newsletter, a live sequence, a live step and a live
                automation. Each change to a live record happens only after the
                user confirms it.
            </p>

            <div class="space-y-10">
                <div
                    v-for="tool in tools"
                    :id="tool.id"
                    :key="tool.name"
                    class="border-b pb-10 last:border-b-0 last:pb-0"
                >
                    <div class="mb-6">
                        <code class="text-sm font-bold text-foreground">{{
                            tool.name
                        }}</code>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ tool.description }}
                        </p>
                    </div>

                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0 space-y-6">
                            <FieldList
                                v-if="tool.arguments.length > 0"
                                title="Arguments"
                                :fields="tool.arguments"
                            />
                            <FieldList title="Returns" :fields="tool.returns" />
                        </div>

                        <div class="min-w-0 lg:sticky lg:top-32">
                            <CodeBlock
                                title="Example result"
                                :code="formatJson(tool.exampleResult)"
                                lang="json"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </DocumentationLayout>
</template>
