<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const { activeSection, sidebarItems } = useDocumentationSidebar('email-templates');
</script>

<template>
    <Head title="Email Templates: Reusable Layouts with a Content Slot">
        <meta
            head-key="description"
            name="description"
            content="Design a reusable email template once, then drop the body of each newsletter or sequence step into its editable content slot in BYOMailer."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Email Templates</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Design a reusable shell once, then drop a per-newsletter or
                per-step body into its editable content slot.
            </p>
        </div>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">How it works</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Content slot</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Every template must contain exactly one
                            <code>&#123;&#123; content &#125;&#125;</code>
                            placeholder. That slot is the only region a
                            newsletter or sequence step can edit once the
                            template is applied — header, footer, layout,
                            branding stay locked.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Default template</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Each client has exactly one default template. New
                            newsletters and sequence steps pre-apply it
                            automatically. Set the default from the templates
                            list by clicking the star on any template.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Starters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Starter templates are not usable directly. When you
                            click <strong>New template</strong> you pick a
                            starter or start blank — either way, a fresh
                            client-owned copy opens in the editor.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Unsubscribe link</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            BYOMailer no longer appends an unsubscribe footer
                            automatically. Templates must include a
                            <code>&#123;&#123; unsubscribe_url &#125;&#125;</code>
                            anchor. Saving without one triggers a deliverability
                            warning.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Creating a template</h2>
            <ol class="ml-6 list-decimal space-y-2 text-sm text-muted-foreground">
                <li>Go to <code>/email-templates</code> and click <strong>New template</strong>.</li>
                <li>Pick a starter or choose <strong>Start from scratch</strong>.</li>
                <li>Edit shell content freely. Use the placeholder button in the toolbar to insert <code>&#123;&#123; content &#125;&#125;</code> exactly once.</li>
                <li>Keep the unsubscribe + manage-preferences links at the bottom.</li>
                <li>Save. The first template you create becomes your default automatically.</li>
            </ol>
        </section>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Applying a template</h2>
            <p class="mb-4 text-muted-foreground">
                Newsletter and sequence step editors always open with the
                client's default template applied. To switch templates, click
                <strong>Change template</strong>. The editor shows the full
                template HTML but only the content slot is editable — the rest
                is visually muted and locked.
            </p>
            <p class="mb-4 text-muted-foreground">
                Switching templates migrates your current slot content into the
                new shell. Removing the template unwraps your slot back into a
                plain editor.
            </p>
            <p class="text-muted-foreground">
                A/B tests share a single template between variants A and B —
                each variant has its own slot content, not its own template.
            </p>
        </section>

        <section class="mb-12">
            <h2 class="mb-4 text-2xl font-bold">Template variables</h2>
            <p class="mb-4 text-muted-foreground">
                Templates support the same
                <code class="text-sm">&#123;&#123; variable &#125;&#125;</code>
                syntax used everywhere else. Variables resolve per recipient at
                send time:
            </p>
            <pre class="rounded-md border border-border bg-muted p-4 text-sm"><code>&lt;h1&gt;Welcome, &#123;&#123; name &#125;&#125;!&lt;/h1&gt;
&lt;p&gt;Thanks for signing up with &#123;&#123; email &#125;&#125;.&lt;/p&gt;
&lt;p style="font-size:12px"&gt;
  &lt;a href="&#123;&#123; unsubscribe_url &#125;&#125;"&gt;Unsubscribe&lt;/a&gt; ·
  &lt;a href="&#123;&#123; preferences_url &#125;&#125;"&gt;Manage preferences&lt;/a&gt;
&lt;/p&gt;</code></pre>
            <p class="mt-4 text-muted-foreground">
                Any contact attribute is available, plus
                <code class="text-sm">&#123;&#123; unsubscribe_url &#125;&#125;</code>
                and
                <code class="text-sm">&#123;&#123; preferences_url &#125;&#125;</code>
                which are per-email signed URLs.
            </p>
        </section>
    </DocumentationLayout>
</template>
