<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Copy, Check, Download, Key } from 'lucide-vue-next';
import { ref, computed } from 'vue';

import { Badge } from '@/components/ui/badge';
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


interface Endpoint {
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    path: string;
    description: string;
    request?: {
        headers?: Record<string, string>;
        body?: Record<string, unknown>;
    };
    response: {
        status: number;
        body: Record<string, unknown>;
    };
}

interface Section {
    title: string;
    description: string;
    endpoints: Endpoint[];
}

const copiedId = ref<string | null>(null);

const copyToClipboard = async (text: string, id: string) => {
    await navigator.clipboard.writeText(text);
    copiedId.value = id;
    setTimeout(() => {
        copiedId.value = null;
    }, 2000);
};

const formatJson = (obj: Record<string, unknown>): string => {
    return JSON.stringify(obj, null, 2);
};

const methodColors: Record<string, string> = {
    GET: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    POST: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    PUT: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    PATCH: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    DELETE: 'bg-red-500/10 text-red-600 dark:text-red-400',
};

const sections: Section[] = [
    {
        title: 'Contacts',
        description: 'Manage your email contacts within lists',
        endpoints: [
            {
                method: 'POST',
                path: '/api/v1/lists/{listId}/contacts',
                description:
                    'Create a new contact or update an existing one and assign them to the specified list.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: {
                        email: 'john@example.com',
                        name: 'John Doe',
                        tags: ['newsletter', 'customer'],
                        company: 'Acme Inc',
                        phone: '+1234567890',
                    },
                },
                response: {
                    status: 201,
                    body: {
                        data: {
                            id: 1,
                            uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                            name: 'John Doe',
                            email: 'john@example.com',
                            extra_attributes: {
                                company: 'Acme Inc',
                                phone: '+1234567890',
                            },
                            unsubscribed_at: null,
                            unsubscribed_by_contact: false,
                            tags: [
                                {
                                    id: 1,
                                    uuid: 'tag-uuid-1',
                                    name: 'newsletter',
                                    created_at: '2024-01-15T10:30:00.000000Z',
                                },
                                {
                                    id: 2,
                                    uuid: 'tag-uuid-2',
                                    name: 'customer',
                                    created_at: '2024-01-15T10:30:00.000000Z',
                                },
                            ],
                            lists: [
                                {
                                    id: 1,
                                    uuid: 'list-uuid-1',
                                    name: 'Weekly Newsletter',
                                    created_at: '2024-01-10T08:00:00.000000Z',
                                },
                            ],
                            created_at: '2024-01-15T10:30:00.000000Z',
                        },
                    },
                },
            },
            {
                method: 'PUT',
                path: '/api/v1/contacts/{contactId}',
                description: 'Update an existing contact by ID.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: {
                        name: 'John Smith',
                        tags: ['vip', 'customer'],
                    },
                },
                response: {
                    status: 200,
                    body: {
                        data: {
                            id: 1,
                            uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                            name: 'John Smith',
                            email: 'john@example.com',
                            extra_attributes: {
                                company: 'Acme Inc',
                                phone: '+1234567890',
                            },
                            unsubscribed_at: null,
                            unsubscribed_by_contact: false,
                            tags: [
                                {
                                    id: 3,
                                    uuid: 'tag-uuid-3',
                                    name: 'vip',
                                    created_at: '2024-01-15T10:30:00.000000Z',
                                },
                                {
                                    id: 2,
                                    uuid: 'tag-uuid-2',
                                    name: 'customer',
                                    created_at: '2024-01-15T10:30:00.000000Z',
                                },
                            ],
                            lists: [
                                {
                                    id: 1,
                                    uuid: 'list-uuid-1',
                                    name: 'Weekly Newsletter',
                                    created_at: '2024-01-10T08:00:00.000000Z',
                                },
                            ],
                            created_at: '2024-01-15T10:30:00.000000Z',
                        },
                    },
                },
            },
            {
                method: 'DELETE',
                path: '/api/v1/lists/{listId}/contacts/{contactId}',
                description:
                    'Remove a contact from a specific list. The contact is not deleted, only disassociated from the list.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        Accept: 'application/json',
                    },
                },
                response: {
                    status: 200,
                    body: {
                        data: {
                            id: 1,
                            uuid: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                            name: 'John Doe',
                            email: 'john@example.com',
                            extra_attributes: {},
                            unsubscribed_at: null,
                            unsubscribed_by_contact: false,
                            tags: [],
                            lists: [],
                            created_at: '2024-01-15T10:30:00.000000Z',
                        },
                    },
                },
            },
        ],
    },
    {
        title: 'Tags',
        description: 'Organize contacts with tags',
        endpoints: [
            {
                method: 'GET',
                path: '/api/v1/tags',
                description: 'Get all tags for the authenticated user.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        Accept: 'application/json',
                    },
                },
                response: {
                    status: 200,
                    body: {
                        data: [
                            {
                                id: 1,
                                uuid: 'tag-uuid-1',
                                name: 'newsletter',
                                created_at: '2024-01-15T10:30:00.000000Z',
                            },
                            {
                                id: 2,
                                uuid: 'tag-uuid-2',
                                name: 'customer',
                                created_at: '2024-01-14T09:00:00.000000Z',
                            },
                        ],
                    },
                },
            },
            {
                method: 'POST',
                path: '/api/v1/tags',
                description: 'Create a new tag.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: {
                        name: 'premium',
                    },
                },
                response: {
                    status: 201,
                    body: {
                        data: {
                            id: 3,
                            uuid: 'tag-uuid-3',
                            name: 'premium',
                            created_at: '2024-01-16T11:00:00.000000Z',
                        },
                    },
                },
            },
            {
                method: 'PATCH',
                path: '/api/v1/tags/{tagId}',
                description: 'Update an existing tag.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: {
                        name: 'premium-user',
                    },
                },
                response: {
                    status: 200,
                    body: {
                        data: {
                            id: 3,
                            uuid: 'tag-uuid-3',
                            name: 'premium-user',
                            created_at: '2024-01-16T11:00:00.000000Z',
                        },
                    },
                },
            },
            {
                method: 'DELETE',
                path: '/api/v1/tags/{tagId}',
                description: 'Delete a tag.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        Accept: 'application/json',
                    },
                },
                response: {
                    status: 204,
                    body: {},
                },
            },
        ],
    },
    {
        title: 'Lists',
        description: 'Manage mailing lists',
        endpoints: [
            {
                method: 'GET',
                path: '/api/v1/lists',
                description: 'Get all mailing lists for the authenticated user.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        Accept: 'application/json',
                    },
                },
                response: {
                    status: 200,
                    body: {
                        data: [
                            {
                                id: 1,
                                uuid: 'list-uuid-1',
                                name: 'Weekly Newsletter',
                                created_at: '2024-01-10T08:00:00.000000Z',
                            },
                            {
                                id: 2,
                                uuid: 'list-uuid-2',
                                name: 'Product Updates',
                                created_at: '2024-01-12T15:30:00.000000Z',
                            },
                        ],
                    },
                },
            },
            {
                method: 'POST',
                path: '/api/v1/lists',
                description: 'Create a new mailing list.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: {
                        name: 'Beta Testers',
                    },
                },
                response: {
                    status: 201,
                    body: {
                        data: {
                            id: 3,
                            uuid: 'list-uuid-3',
                            name: 'Beta Testers',
                            created_at: '2024-01-16T12:00:00.000000Z',
                        },
                    },
                },
            },
            {
                method: 'PATCH',
                path: '/api/v1/lists/{listId}',
                description: 'Update an existing mailing list.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: {
                        name: 'Early Access Users',
                    },
                },
                response: {
                    status: 200,
                    body: {
                        data: {
                            id: 3,
                            uuid: 'list-uuid-3',
                            name: 'Early Access Users',
                            created_at: '2024-01-16T12:00:00.000000Z',
                        },
                    },
                },
            },
            {
                method: 'DELETE',
                path: '/api/v1/lists/{listId}',
                description: 'Delete a mailing list.',
                request: {
                    headers: {
                        Authorization: 'Bearer {your-api-token}',
                        Accept: 'application/json',
                    },
                },
                response: {
                    status: 204,
                    body: {},
                },
            },
        ],
    },
];

const markdownUrl = documentationMarkdown().url;

const baseUrl = computed(() => {
    if (typeof window !== 'undefined') {
        return window.location.origin;
    }
    return '';
});

const { activeSection, sidebarItems } = useDocumentationSidebar('api');
</script>

<template>
    <Head title="API Documentation" />

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

        <!-- API Sections -->
        <section
            v-for="section in sections"
            :id="section.title.toLowerCase()"
            :key="section.title"
            class="mb-16"
        >
            <h2 class="text-2xl font-bold">{{ section.title }}</h2>
            <p class="mt-2 text-muted-foreground">
                {{ section.description }}
            </p>

            <div class="mt-6 space-y-8">
                <Card
                    v-for="(endpoint, index) in section.endpoints"
                    :key="index"
                    class="overflow-hidden"
                >
                    <CardHeader class="border-b bg-muted/30">
                        <div class="flex items-center gap-3">
                            <Badge
                                :class="methodColors[endpoint.method]"
                                class="font-mono font-semibold"
                            >
                                {{ endpoint.method }}
                            </Badge>
                            <code class="text-sm font-medium">
                                {{ endpoint.path }}
                            </code>
                        </div>
                        <CardDescription class="mt-2">
                            {{ endpoint.description }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6 pt-6">
                        <!-- Request -->
                        <div v-if="endpoint.request">
                            <h4 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                Request
                            </h4>

                            <!-- Headers -->
                            <div v-if="endpoint.request.headers" class="mb-4">
                                <p class="mb-2 text-sm font-medium text-muted-foreground">
                                    Headers
                                </p>
                                <div class="relative">
                                    <pre class="overflow-x-auto rounded-lg bg-zinc-950 p-4 text-sm text-zinc-100"><code>{{ formatJson(endpoint.request.headers) }}</code></pre>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="absolute right-2 top-2 h-8 w-8 p-0 text-zinc-400 hover:text-zinc-100"
                                        @click="copyToClipboard(formatJson(endpoint.request.headers ?? {}), `${section.title}-${index}-headers`)"
                                    >
                                        <Check v-if="copiedId === `${section.title}-${index}-headers`" class="h-4 w-4" />
                                        <Copy v-else class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>

                            <!-- Body -->
                            <div v-if="endpoint.request.body">
                                <p class="mb-2 text-sm font-medium text-muted-foreground">
                                    Body
                                </p>
                                <div class="relative">
                                    <pre class="overflow-x-auto rounded-lg bg-zinc-950 p-4 text-sm text-zinc-100"><code>{{ formatJson(endpoint.request.body) }}</code></pre>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="absolute right-2 top-2 h-8 w-8 p-0 text-zinc-400 hover:text-zinc-100"
                                        @click="copyToClipboard(formatJson(endpoint.request.body ?? {}), `${section.title}-${index}-body`)"
                                    >
                                        <Check v-if="copiedId === `${section.title}-${index}-body`" class="h-4 w-4" />
                                        <Copy v-else class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <!-- Response -->
                        <div>
                            <h4 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                Response
                                <Badge variant="outline" class="ml-2 font-mono">
                                    {{ endpoint.response.status }}
                                </Badge>
                            </h4>
                            <div class="relative">
                                <pre
                                    v-if="Object.keys(endpoint.response.body).length > 0"
                                    class="overflow-x-auto rounded-lg bg-zinc-950 p-4 text-sm text-zinc-100"
                                ><code>{{ formatJson(endpoint.response.body) }}</code></pre>
                                <pre
                                    v-else
                                    class="overflow-x-auto rounded-lg bg-zinc-950 p-4 text-sm text-zinc-400 italic"
                                ><code>No content</code></pre>
                                <Button
                                    v-if="Object.keys(endpoint.response.body).length > 0"
                                    variant="ghost"
                                    size="sm"
                                    class="absolute right-2 top-2 h-8 w-8 p-0 text-zinc-400 hover:text-zinc-100"
                                    @click="copyToClipboard(formatJson(endpoint.response.body), `${section.title}-${index}-response`)"
                                >
                                    <Check v-if="copiedId === `${section.title}-${index}-response`" class="h-4 w-4" />
                                    <Copy v-else class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </section>
    </DocumentationLayout>
</template>
