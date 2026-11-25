<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import CodeBlock from '@/components/documentation/CodeBlock.vue';
import FieldList from '@/components/documentation/FieldList.vue';
import type { Field } from '@/components/documentation/FieldList.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

const filterFields: Field[] = [
    {
        name: 'connector',
        type: 'string',
        description: 'How this filter combines with the previous one. Either "and" or "or". Defaults to "and". Ignored on the first filter.',
    },
    {
        name: 'category',
        type: 'string',
        required: true,
        description: 'The type of filter. One of "tags", "lists", "topics", "attributes", "date", "engagement", or "location".',
    },
    {
        name: 'mode',
        type: 'string',
        description: 'Required for tags/lists/topics/attributes ("all", "any", "none") and location ("any", "none"). Omitted for date/engagement.',
    },
    {
        name: 'operator',
        type: 'string',
        description: 'Required for date and engagement filters. See the Categories section for valid operators.',
    },
    {
        name: 'field',
        type: 'string',
        description: 'Required for location filters. Either "country" or "timezone".',
    },
    {
        name: 'values',
        type: 'array | object',
        required: true,
        description: 'The values to match against. Format depends on category (see below).',
    },
];

const fullExample = JSON.stringify(
    {
        filters: [
            {
                connector: 'and',
                category: 'tags',
                mode: 'any',
                values: ['vip', 'premium'],
            },
            {
                connector: 'and',
                category: 'lists',
                mode: 'all',
                values: [1, 5],
            },
            {
                connector: 'or',
                category: 'attributes',
                mode: 'any',
                values: [
                    { key: 'plan', value: 'pro' },
                    { key: 'status', value: 'active' },
                ],
            },
        ],
    },
    null,
    2,
);

const tagsExample = JSON.stringify(
    {
        connector: 'and',
        category: 'tags',
        mode: 'any',
        values: ['vip', 'premium'],
    },
    null,
    2,
);

const listsExample = JSON.stringify(
    {
        connector: 'and',
        category: 'lists',
        mode: 'all',
        values: [1, 5],
    },
    null,
    2,
);

const attributesExample = JSON.stringify(
    {
        connector: 'or',
        category: 'attributes',
        mode: 'any',
        values: [
            { key: 'plan', operator: 'equals', value: 'pro' },
            { key: 'age', operator: 'gt', value: '21' },
        ],
    },
    null,
    2,
);

const topicsExample = JSON.stringify(
    {
        connector: 'and',
        category: 'topics',
        mode: 'any',
        values: [12, 34],
    },
    null,
    2,
);

const dateExample = JSON.stringify(
    {
        connector: 'and',
        category: 'date',
        operator: 'more_than',
        values: { days: 30 },
    },
    null,
    2,
);

const engagementExample = JSON.stringify(
    {
        connector: 'and',
        category: 'engagement',
        operator: 'not_opened',
        values: { newsletter_id: null, within: 'last_n_days', days: 90 },
    },
    null,
    2,
);

const locationExample = JSON.stringify(
    {
        connector: 'or',
        category: 'location',
        field: 'country',
        mode: 'any',
        values: ['US', 'GB'],
    },
    null,
    2,
);

const { activeSection, sidebarItems } = useDocumentationSidebar('api-filters');
</script>

<template>
    <Head title="Filters API: Target Newsletter Recipients">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer API filter reference. Target newsletter recipients by tags, lists, topics, attributes, signup date, engagement, or location. Combine filters with and/or."
        />
    </Head>

    <DocumentationLayout :active-section="activeSection" :sidebar-items="sidebarItems">
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Filters</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Filters let you target specific contacts when sending a newsletter. Each filter narrows the recipient
                list by tags, lists, topics, attributes, signup date, newsletter engagement, or location. Multiple
                filters can be combined with "and" or "or" connectors.
            </p>
        </div>

        <div class="space-y-10">
            <div class="border-b pb-10">
                <h2 class="mb-4 text-2xl font-bold tracking-tight">Filter Object</h2>

                <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                    <div class="min-w-0">
                        <FieldList title="Fields" :fields="filterFields" />
                    </div>

                    <div class="min-w-0">
                        <CodeBlock title="Combined Example" :code="fullExample" />
                    </div>
                </div>
            </div>

            <div class="border-b pb-10">
                <h2 class="mb-2 text-2xl font-bold tracking-tight">Categories</h2>
                <p class="mb-6 text-sm text-muted-foreground">
                    The <code class="text-xs">values</code> format depends on the filter category. All referenced tags,
                    lists, and attributes must exist and belong to your account.
                </p>

                <div class="space-y-8">
                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0">
                            <h3 class="mb-2 text-lg font-semibold">Tags</h3>
                            <p class="text-sm text-muted-foreground">
                                Array of tag name strings. Matches contacts that have the specified tags.
                            </p>
                        </div>
                        <div class="min-w-0">
                            <CodeBlock title="Tags Filter" :code="tagsExample" />
                        </div>
                    </div>

                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0">
                            <h3 class="mb-2 text-lg font-semibold">Lists</h3>
                            <p class="text-sm text-muted-foreground">
                                Array of list ID integers. Matches contacts that belong to the specified lists.
                            </p>
                        </div>
                        <div class="min-w-0">
                            <CodeBlock title="Lists Filter" :code="listsExample" />
                        </div>
                    </div>

                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0">
                            <h3 class="mb-2 text-lg font-semibold">Topics</h3>
                            <p class="text-sm text-muted-foreground">
                                Array of topic ID integers. Matches contacts that are actively subscribed to the specified topics.
                            </p>
                        </div>
                        <div class="min-w-0">
                            <CodeBlock title="Topics Filter" :code="topicsExample" />
                        </div>
                    </div>

                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0">
                            <h3 class="mb-2 text-lg font-semibold">Attributes</h3>
                            <p class="text-sm text-muted-foreground">
                                Array of objects with <code class="text-xs">key</code>,
                                <code class="text-xs">operator</code>, and
                                <code class="text-xs">value</code>. Operators:
                                <code class="text-xs">equals</code>, <code class="text-xs">not_equals</code>,
                                <code class="text-xs">contains</code>, <code class="text-xs">not_contains</code>,
                                <code class="text-xs">gt</code>, <code class="text-xs">lt</code>,
                                <code class="text-xs">is_set</code>, <code class="text-xs">is_not_set</code>.
                                <code class="text-xs">value</code> is ignored for
                                <code class="text-xs">is_set</code>/<code class="text-xs">is_not_set</code> and must be
                                numeric for <code class="text-xs">gt</code>/<code class="text-xs">lt</code>.
                            </p>
                        </div>
                        <div class="min-w-0">
                            <CodeBlock title="Attributes Filter" :code="attributesExample" />
                        </div>
                    </div>

                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0">
                            <h3 class="mb-2 text-lg font-semibold">Date</h3>
                            <p class="text-sm text-muted-foreground">
                                Filters on the contact's signup date. Set <code class="text-xs">operator</code> to
                                <code class="text-xs">before</code>/<code class="text-xs">after</code> with
                                <code class="text-xs">values: &#123; date &#125;</code>,
                                <code class="text-xs">in_last</code>/<code class="text-xs">more_than</code> with
                                <code class="text-xs">values: &#123; days &#125;</code>, or
                                <code class="text-xs">between</code> with
                                <code class="text-xs">values: &#123; from, to &#125;</code>.
                            </p>
                        </div>
                        <div class="min-w-0">
                            <CodeBlock title="Date Filter" :code="dateExample" />
                        </div>
                    </div>

                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0">
                            <h3 class="mb-2 text-lg font-semibold">Engagement</h3>
                            <p class="text-sm text-muted-foreground">
                                Filters on newsletter activity. <code class="text-xs">operator</code> is one of
                                <code class="text-xs">opened</code>, <code class="text-xs">clicked</code>,
                                <code class="text-xs">received</code>, <code class="text-xs">not_opened</code>,
                                <code class="text-xs">not_clicked</code>, <code class="text-xs">not_received</code>.
                                <code class="text-xs">received</code> means the newsletter was delivered to the
                                contact (a bounced send does not count).
                                <code class="text-xs">newsletter_id</code> is <code class="text-xs">null</code> for any
                                newsletter; set <code class="text-xs">within</code> to
                                <code class="text-xs">last_n_days</code> with a <code class="text-xs">days</code> count.
                            </p>
                        </div>
                        <div class="min-w-0">
                            <CodeBlock title="Engagement Filter" :code="engagementExample" />
                        </div>
                    </div>

                    <div class="grid min-w-0 items-start gap-8 lg:grid-cols-2">
                        <div class="min-w-0">
                            <h3 class="mb-2 text-lg font-semibold">Location</h3>
                            <p class="text-sm text-muted-foreground">
                                Filters on contact country or timezone. Set <code class="text-xs">field</code> to
                                <code class="text-xs">country</code> or <code class="text-xs">timezone</code>,
                                <code class="text-xs">mode</code> to <code class="text-xs">any</code> or
                                <code class="text-xs">none</code>, and <code class="text-xs">values</code> to an array
                                of strings.
                            </p>
                        </div>
                        <div class="min-w-0">
                            <CodeBlock title="Location Filter" :code="locationExample" />
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <h2 class="mb-2 text-2xl font-bold tracking-tight">Modes</h2>
                <p class="mb-6 text-sm text-muted-foreground">
                    The <code class="text-xs">mode</code> field controls how values are matched against each contact.
                </p>

                <div class="space-y-3">
                    <div class="border-b border-dashed pb-3">
                        <div class="flex items-center gap-2">
                            <code class="text-sm font-medium text-foreground">all</code>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Contact must match every value in the array. For tags, the contact must have all listed
                            tags. For lists, the contact must be in all listed lists.
                        </p>
                    </div>
                    <div class="border-b border-dashed pb-3">
                        <div class="flex items-center gap-2">
                            <code class="text-sm font-medium text-foreground">any</code>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Contact must match at least one value. For tags, the contact needs at least one of the
                            listed tags. This is the most common mode.
                        </p>
                    </div>
                    <div class="border-b border-dashed pb-3 last:border-b-0 last:pb-0">
                        <div class="flex items-center gap-2">
                            <code class="text-sm font-medium text-foreground">none</code>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Contact must not match any value. For tags, the contact must have none of the listed tags.
                            Useful for excluding segments.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </DocumentationLayout>
</template>
