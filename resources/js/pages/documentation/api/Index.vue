<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Copy, Check, Download, Key } from 'lucide-vue-next';
import { ref, computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';
import { login } from '@/routes';
import { index as apiTokensIndex } from '@/routes/api-tokens';
import { postman as documentationPostman, openapi as documentationOpenapi, markdown as documentationMarkdown } from '@/routes/documentation';

const copiedId = ref<string | null>(null);

const copyToClipboard = async (text: string, id: string) => {
    await navigator.clipboard.writeText(text);
    copiedId.value = id;
    setTimeout(() => {
        copiedId.value = null;
    }, 2000);
};

const markdownUrl = documentationMarkdown().url;

const baseUrl = computed(() => {
    if (typeof window !== 'undefined') {
        return window.location.origin;
    }
    return '';
});

const { activeSection, sidebarItems } = useDocumentationSidebar('api-index');
</script>

<template>
    <Head title="REST API Documentation">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer REST API documentation. Manage contacts, lists, tags, topics, newsletters, sequences, templates, and webhooks for email marketing on your Amazon SES account."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <!-- Introduction -->
        <div class="mb-12">
            <h1 class="text-4xl font-bold tracking-tight">
                API Documentation
            </h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Integrate with the BYOMailer API to manage contacts,
                tags, and mailing lists programmatically.
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                <Button
                    as="a"
                    :href="markdownUrl"
                    rel="noreferrer"
                    target="_blank"
                    variant="secondary"
                >
                    View as Markdown
                </Button>
            </div>
        </div>

        <!-- Getting Started Section -->
        <section id="getting-started" class="mb-16">
            <h2 class="text-2xl font-bold">Getting Started</h2>
            <p class="mt-2 text-muted-foreground">
                Follow these steps to start using the API.
            </p>

            <div class="mt-6 space-y-4">
                <!-- Step 1: Create Account -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">
                                1
                            </div>
                            <CardTitle class="text-base">
                                Create an Account
                            </CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            If you don't have an account yet,
                            <Link
                                :href="login()"
                                class="font-medium text-primary hover:underline"
                            >
                                sign up or log in
                            </Link>
                            to get started.
                        </p>
                    </CardContent>
                </Card>

                <!-- Step 2: Generate API Token -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">
                                2
                            </div>
                            <CardTitle class="text-base">
                                Generate an API Token
                            </CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Navigate to
                            <Link
                                v-if="$page.props.auth.user"
                                :href="apiTokensIndex()"
                                class="font-medium text-primary hover:underline"
                            >
                                Settings &rarr; API Tokens
                            </Link>
                            <span v-else class="font-medium">
                                Settings &rarr; API Tokens
                            </span>
                            in your dashboard to create a new API token.
                        </p>
                        <div class="flex items-center gap-2 rounded-lg bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                            <Key class="h-4 w-4 shrink-0" />
                            <span>
                                Your token will only be shown once. Make sure to copy and store it securely.
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <!-- Step 3: Make Requests -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">
                                3
                            </div>
                            <CardTitle class="text-base">
                                Start Making Requests
                            </CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p class="text-sm text-muted-foreground">
                            Include your API token in the Authorization header of all requests.
                            See the Authentication section below for details.
                        </p>
                        <div class="flex flex-wrap gap-3">
                            <a
                                :href="documentationPostman().url"
                                class="inline-flex items-center gap-2 rounded-lg border border-border bg-background px-4 py-2 text-sm font-medium transition-colors hover:bg-muted"
                            >
                                <Download class="h-4 w-4" />
                                Download Postman Collection
                            </a>
                            <a
                                :href="documentationOpenapi().url"
                                class="inline-flex items-center gap-2 rounded-lg border border-border bg-background px-4 py-2 text-sm font-medium transition-colors hover:bg-muted"
                            >
                                <Download class="h-4 w-4" />
                                Download OpenAPI Schema
                            </a>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </section>

        <!-- Authentication Section -->
        <section id="authentication" class="mb-16">
            <h2 class="text-2xl font-bold">Authentication</h2>
            <p class="mt-2 text-muted-foreground">
                All API requests require authentication using a Bearer
                token. You can create API tokens from your
                <Link
                    v-if="$page.props.auth.user"
                    :href="apiTokensIndex()"
                    class="font-medium text-primary hover:underline"
                >
                    account settings
                </Link>
                <span v-else>account settings</span>.
            </p>

            <Card class="mt-6">
                <CardHeader>
                    <CardTitle class="text-base">
                        Authorization Header
                    </CardTitle>
                    <CardDescription>
                        Include this header in all API requests
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="relative">
                        <pre class="overflow-x-auto rounded-lg bg-zinc-950 p-4 text-sm text-zinc-100"><code>Authorization: Bearer your-api-token-here</code></pre>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="absolute right-2 top-2 h-8 w-8 p-0 text-zinc-400 hover:text-zinc-100"
                            @click="copyToClipboard('Authorization: Bearer your-api-token-here', 'auth-header')"
                        >
                            <Check v-if="copiedId === 'auth-header'" class="h-4 w-4" />
                            <Copy v-else class="h-4 w-4" />
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="mt-4">
                <CardHeader>
                    <CardTitle class="text-base">Base URL</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="relative">
                        <pre class="overflow-x-auto rounded-lg bg-zinc-950 p-4 text-sm text-zinc-100"><code>{{ baseUrl }}/api</code></pre>
                    </div>
                </CardContent>
            </Card>
        </section>
    </DocumentationLayout>
</template>
