<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import type { Endpoint } from '@/components/documentation/EndpointCard.vue';
import EndpointCard from '@/components/documentation/EndpointCard.vue';
import type { Field } from '@/components/documentation/FieldList.vue';
import { useDocumentationSidebar } from '@/composables/useDocumentationSidebar';
import DocumentationLayout from '@/layouts/documentation/DocumentationLayout.vue';

type Mailable = {
    path: string;
    subject: string;
    pathParams: Field[];
    linkName: string;
    examplePath: string;
};

const linkFields: Field[] = [
    {
        name: 'delivered',
        type: 'integer',
        description: 'The number of delivered mails. The click rate uses it.',
    },
    {
        name: 'links',
        type: 'array',
        description:
            'One row for each link. The links with the most unique contacts come first.',
        children: [
            {
                name: 'key',
                type: 'string',
                description:
                    'The link key: name:<link name> or url:<url>. Send it as the link parameter of the contacts endpoint.',
            },
            {
                name: 'name',
                type: 'string',
                description: 'The link name, or null for an unnamed link.',
            },
            {
                name: 'url',
                type: 'string',
                description:
                    'The URL without utm_* parameters and without the #fragment. For a named link, the URL with the most clicks.',
            },
            {
                name: 'unique_contacts',
                type: 'integer',
                description: 'The number of contacts who clicked the link.',
            },
            {
                name: 'total_clicks',
                type: 'integer',
                description: 'All clicks on the link, also repeated clicks.',
            },
            {
                name: 'click_rate',
                type: 'number',
                description:
                    'Unique contacts / delivered * 100, rounded to 2 decimals. Null when no mail is delivered.',
            },
        ],
    },
];

const contactFields: Field[] = [
    {
        name: 'contact_mail_id',
        type: 'integer',
        description: 'The ID of the mail that the contact received.',
    },
    { name: 'contact_id', type: 'integer', description: 'The contact ID.' },
    { name: 'email', type: 'string', description: 'The contact email.' },
    {
        name: 'name',
        type: 'string',
        description: 'The contact name, or null.',
    },
    {
        name: 'clicks',
        type: 'integer',
        description: 'The number of clicks of the contact on this link.',
    },
    {
        name: 'first_clicked_at',
        type: 'string',
        description: 'ISO 8601 time of the first click on this link.',
    },
];

const linkEndpoints = (mailable: Mailable): Endpoint[] => [
    {
        method: 'GET',
        path: `${mailable.path}/links`,
        description: `List the links of ${mailable.subject} with their clicks. A named link groups by its link name. Any other link groups by its URL without utm_* parameters and without the #fragment.`,
        pathParams: mailable.pathParams,
        responseFields: linkFields,
        response: {
            status: 200,
            body: {
                data: {
                    delivered: 3,
                    links: [
                        {
                            key: `name:${mailable.linkName}`,
                            name: mailable.linkName,
                            url: 'https://example.com/sale',
                            unique_contacts: 2,
                            total_clicks: 3,
                            click_rate: 66.67,
                        },
                        {
                            key: 'url:https://example.com/blog',
                            name: null,
                            url: 'https://example.com/blog',
                            unique_contacts: 1,
                            total_clicks: 1,
                            click_rate: 33.33,
                        },
                    ],
                },
            },
        },
    },
    {
        method: 'GET',
        path: `${mailable.path}/links/contacts`,
        description: `List the contacts who clicked one link of ${mailable.subject}, cursor-paginated. Query parameters: link (required, a link key from the link list, for example name:${mailable.linkName} or url:https://example.com/blog), per_page (default 50, max 200) and cursor (next or previous page token). If link does not start with name: or url:, the API returns 422 "The link must be a key from the link list."`,
        pathParams: mailable.pathParams,
        responseFields: contactFields,
        response: {
            status: 200,
            body: {
                data: [
                    {
                        contact_mail_id: 9081,
                        contact_id: 42,
                        email: 'john@example.com',
                        name: 'John Doe',
                        clicks: 2,
                        first_clicked_at: '2026-10-27T14:05:12+00:00',
                    },
                ],
                links: { first: null, last: null, prev: null, next: null },
                meta: {
                    path: `https://mail.example.com${mailable.examplePath}/links/contacts`,
                    per_page: 50,
                    next_cursor: null,
                    prev_cursor: null,
                },
            },
        },
    },
];

const endpoints: Endpoint[] = [
    ...linkEndpoints({
        path: '/api/v1/newsletters/{newsletterId}',
        subject: 'a newsletter',
        pathParams: [
            {
                name: 'newsletterId',
                type: 'integer',
                required: true,
                description: 'The newsletter ID.',
            },
        ],
        linkName: 'black-friday',
        examplePath: '/api/v1/newsletters/12',
    }),
    ...linkEndpoints({
        path: '/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}',
        subject: 'a sequence step',
        pathParams: [
            {
                name: 'sequenceId',
                type: 'integer',
                required: true,
                description: 'The ID of the sequence of the step.',
            },
            {
                name: 'sequenceStepId',
                type: 'integer',
                required: true,
                description: 'The ID of the step.',
            },
        ],
        linkName: 'pricing',
        examplePath: '/api/v1/sequences/7/steps/31',
    }),
];

const { activeSection, sidebarItems } =
    useDocumentationSidebar('api-link-clicks');
</script>

<template>
    <Head title="Link Clicks API: Who Clicked Which Link">
        <meta
            head-key="description"
            name="description"
            content="BYOMailer Link Clicks API reference. Read the clicks of each link in a newsletter or a sequence step, and list the contacts who clicked one link."
        />
    </Head>

    <DocumentationLayout
        :active-section="activeSection"
        :sidebar-items="sidebarItems"
    >
        <div class="mb-8">
            <h1 class="text-4xl font-bold tracking-tight">Link clicks</h1>
            <p class="mt-4 text-lg text-muted-foreground">
                Read who clicked which link in a newsletter or a sequence step.
            </p>
            <ul
                class="mt-4 list-disc space-y-2 pl-5 text-sm text-muted-foreground"
            >
                <li>
                    To name a link, add a
                    <code class="font-mono">data-bm-link</code> attribute to its
                    &lt;a&gt; tag, for example
                    <code class="font-mono">data-bm-link="black-friday"</code>.
                    A named link keeps its clicks together when its URL changes.
                </li>
                <li>
                    BYOMailer changes a link name to lowercase letters, digits
                    and dashes, with a maximum of 100 characters. "Black Friday"
                    becomes
                    <code class="font-mono">black-friday</code>.
                </li>
                <li>
                    Only emails that you send after you name a link carry the
                    link name.
                </li>
                <li>
                    BYOMailer does not filter bot clicks. The clicks can include
                    link scanners, for example Microsoft Safe Links.
                </li>
            </ul>
        </div>

        <div class="space-y-10">
            <EndpointCard
                v-for="(endpoint, index) in endpoints"
                :key="index"
                :endpoint="endpoint"
                :id="`link-clicks-${index}`"
            />
        </div>
    </DocumentationLayout>
</template>
