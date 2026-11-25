<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentationPostmanController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $baseUrl = config('app.url');
        $appName = config('app.name');

        $collection = [
            'info' => [
                '_postman_id' => (string) uuid_create(),
                'name' => $appName.' API',
                'description' => 'API collection for '.$appName.'. Set the api_token variable to your generated API token before making requests.',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => [
                'type' => 'bearer',
                'bearer' => [
                    [
                        'key' => 'token',
                        'value' => '{{api_token}}',
                        'type' => 'string',
                    ],
                ],
            ],
            'variable' => [
                [
                    'key' => 'base_url',
                    'value' => $baseUrl,
                    'type' => 'string',
                ],
                [
                    'key' => 'api_token',
                    'value' => '',
                    'type' => 'string',
                ],
            ],
            'item' => $this->buildFolders(),
        ];

        return response()->streamDownload(function () use ($collection): void {
            echo json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, 'byomailer-postman-collection.json', [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFolders(): array
    {
        return [
            $this->contactsFolder(),
            $this->listsFolder(),
            $this->tagsFolder(),
            $this->topicsFolder(),
            $this->blocksFolder(),
            $this->withLinkRequests($this->newslettersFolder(), 'Newsletter', 'api/v1/newsletters/:newsletterId', 'a newsletter', 'black-friday', 'https://example.com/blog'),
            $this->withLinkRequests($this->sequencesFolder(), 'Sequence Step', 'api/v1/sequences/:sequenceId/steps/:sequenceStepId', 'a sequence step', 'pricing', 'https://example.com/pricing'),
            $this->projectsFolder(),
            $this->emailIdentitiesFolder(),
            $this->emailTemplatesFolder(),
            $this->emailLogsFolder(),
            $this->webhooksFolder(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webhooksFolder(): array
    {
        return [
            'name' => 'Webhook Endpoints',
            'description' => 'Register HTTPS endpoints that receive signed POST callbacks on subscription, send, and sequence events. Admin-only.',
            'item' => [
                $this->buildRequest(
                    'List Webhook Endpoints',
                    'GET',
                    'api/v1/webhook-endpoints',
                    'Retrieve all webhook endpoints for the authenticated client. Cursor-paginated. The signing secret is never returned here.'
                ),
                $this->buildRequest(
                    'Create Webhook Endpoint',
                    'POST',
                    'api/v1/webhook-endpoints',
                    'Create an endpoint. The plaintext signing secret is returned ONCE in this response — store it to verify the X-Byomailer-Signature header.',
                    [
                        'url' => 'https://example.com/webhooks/byomailer',
                        'events' => ['contact.subscribed', 'newsletter.sent'],
                        'description' => 'CRM sync',
                    ]
                ),
                $this->buildRequest(
                    'Get Webhook Endpoint',
                    'GET',
                    'api/v1/webhook-endpoints/:webhookEndpointId',
                    'Retrieve a single endpoint (without the secret).'
                ),
                $this->buildRequest(
                    'Update Webhook Endpoint',
                    'PATCH',
                    'api/v1/webhook-endpoints/:webhookEndpointId',
                    'Update only active and description. URL and events are immutable — recreate the endpoint to change them.',
                    [
                        'active' => false,
                        'description' => 'Paused for maintenance',
                    ]
                ),
                $this->buildRequest(
                    'Delete Webhook Endpoint',
                    'DELETE',
                    'api/v1/webhook-endpoints/:webhookEndpointId',
                    'Delete an endpoint and stop all deliveries to it.'
                ),
                $this->buildRequest(
                    'List Deliveries',
                    'GET',
                    'api/v1/webhook-endpoints/:webhookEndpointId/deliveries',
                    'Cursor-paginated delivery log for an endpoint (status, response code, truncated body, attempts).'
                ),
                $this->buildRequest(
                    'Send Test Delivery',
                    'POST',
                    'api/v1/webhook-endpoints/:webhookEndpointId/test',
                    'Queue a sample test delivery to the endpoint. Rate-limited per client.'
                ),
                $this->buildRequest(
                    'Regenerate Secret',
                    'POST',
                    'api/v1/webhook-endpoints/:webhookEndpointId/secret',
                    'Rotate the signing secret. The new plaintext secret is returned ONCE; existing receivers fail signature verification until updated.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sequencesFolder(): array
    {
        return [
            'name' => 'Sequences',
            'description' => 'Read automated email sequences, their step content and delivery stats, set step filters, and set the content variable defaults',
            'item' => [
                $this->buildRequest(
                    'Get All Sequences',
                    'GET',
                    'api/v1/sequences',
                    'Retrieve a cursor-paginated list of sequences for the authenticated account. Supports filtering by status (draft, live, paused. paused is the same as draft: a paused sequence has the status draft), search (partial name match), and project (?project=<projectId> — only sequences assigned to that project). Pagination via per_page and cursor (meta.next_cursor / meta.prev_cursor).'
                ),
                $this->buildRequest(
                    'Get Sequence',
                    'GET',
                    'api/v1/sequences/:sequenceId',
                    'Retrieve a single sequence with the full content of every step.'
                ),
                $this->buildRequest(
                    'Get Sequence Stats',
                    'GET',
                    'api/v1/sequences/:sequenceId/stats',
                    'Retrieve sequence-level delivery and engagement stats: contact, unsubscribe and click totals plus aggregate mail metrics across all steps.'
                ),
                $this->buildRequest(
                    'Get Sequence Variables',
                    'GET',
                    'api/v1/sequences/:sequenceId/variables',
                    'Retrieve the {{ variables }} that the steps of the sequence use (detected_variables) and the saved default of each one (content_variables). A default fills a variable when the contact has no value for it.'
                ),
                $this->buildRequest(
                    'Update Sequence Variables',
                    'PATCH',
                    'api/v1/sequences/:sequenceId/variables',
                    'Replace the content variable defaults of the sequence. The object replaces all saved defaults, and an empty object clears them. Each default is text of up to 255 characters, the same rule as the dashboard. A default can name a variable that no step uses yet. Works on draft and live sequences.',
                    [
                        'content_variables' => [
                            'checkout_url' => 'https://example.com/checkout',
                            'first_name' => 'there',
                        ],
                    ]
                ),
                $this->buildRequest(
                    'Get Sequence Step',
                    'GET',
                    'api/v1/sequences/:sequenceId/steps/:sequenceStepId',
                    'Retrieve the full content of a single sequence step.'
                ),
                $this->buildRequest(
                    'Update Sequence Step Filters',
                    'PATCH',
                    'api/v1/sequences/:sequenceId/steps/:sequenceStepId/filters',
                    'Replace the filters of a single sequence step. A contact who does not match them skips only this email and continues to the next step. Works on draft and live steps. Send an empty filters array to clear them. Returns the updated step.',
                    [
                        'filters' => [
                            ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
                        ],
                    ]
                ),
                $this->buildRequest(
                    'Get Sequence Step Stats',
                    'GET',
                    'api/v1/sequences/:sequenceId/steps/:sequenceStepId/stats',
                    'Retrieve delivery and engagement stats for a single sequence step: contacts reached, unsubscribe and click totals plus mail metrics.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectsFolder(): array
    {
        return [
            'name' => 'Projects',
            'description' => 'Organize newsletters, sequences, and automations into named projects. Filter newsletters and sequences by project via ?project=<projectId>.',
            'item' => [
                $this->buildRequest(
                    'List Projects',
                    'GET',
                    'api/v1/projects',
                    'Retrieve all projects for the authenticated client. Cursor-paginated (per_page up to 100, cursor for next/previous page; response includes meta.next_cursor / meta.prev_cursor). Each project includes newsletters_count, sequences_count, automations_count, and visual_automations_count.'
                ),
                $this->buildRequest(
                    'Get Project',
                    'GET',
                    'api/v1/projects/:projectId',
                    'Retrieve a single project by ID, including attached resource counts.'
                ),
                $this->buildRequest(
                    'Create Project',
                    'POST',
                    'api/v1/projects',
                    'Create a new project. The name must be unique per account (case-insensitive).',
                    [
                        'name' => 'Q3 Launch',
                    ]
                ),
                $this->buildRequest(
                    'Update Project',
                    'PATCH',
                    'api/v1/projects/:projectId',
                    'Rename a project. The name must be unique per account (case-insensitive).',
                    [
                        'name' => 'Q3 Product Launch',
                    ]
                ),
                $this->buildRequest(
                    'Delete Project',
                    'DELETE',
                    'api/v1/projects/:projectId',
                    'Delete a project. Attached newsletters, sequences, and automations are detached, not deleted.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function topicsFolder(): array
    {
        return [
            'name' => 'Topics',
            'description' => 'Manage topics within a list. Subscribers manage their preferences per topic via the preference center.',
            'item' => [
                $this->buildRequest(
                    'Get All Topics',
                    'GET',
                    'api/v1/lists/:listId/topics',
                    'Retrieve all topics for a given list. Cursor-paginated (per_page up to 100, cursor for next/previous page; response includes meta.next_cursor / meta.prev_cursor).'
                ),
                $this->buildRequest(
                    'Create Topic',
                    'POST',
                    'api/v1/lists/:listId/topics',
                    'Create a new topic on a list. All existing contacts on the list will be auto-subscribed to the new topic asynchronously.',
                    [
                        'name' => 'Product Updates',
                        'description' => 'Release notes and feature announcements',
                    ]
                ),
                $this->buildRequest(
                    'Update Topic',
                    'PATCH',
                    'api/v1/lists/:listId/topics/:topicId',
                    'Update a topic.',
                    [
                        'name' => 'Product News',
                        'description' => null,
                    ]
                ),
                $this->buildRequest(
                    'Delete Topic',
                    'DELETE',
                    'api/v1/lists/:listId/topics/:topicId',
                    'Delete a topic and all subscription state for it.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactsFolder(): array
    {
        return [
            'name' => 'Contacts',
            'description' => 'Manage contacts within lists',
            'item' => [
                $this->buildRequest(
                    'List Contacts',
                    'GET',
                    'api/v1/contacts',
                    'List all contacts for the authenticated client. Cursor-paginated. Supports filters: lists[], tags[], topics[], search, include_unsubscribed, per_page, cursor.'
                ),
                $this->buildRequest(
                    'Get Contact',
                    'GET',
                    'api/v1/contacts/:contactId',
                    'Retrieve a single contact by ID, including tags, lists, and topics.'
                ),
                $this->buildRequest(
                    'Create/Update Contact in List',
                    'POST',
                    'api/v1/lists/:listId/contacts',
                    'Create a new contact or update an existing one and assign them to the specified list.',
                    [
                        'email' => 'john@example.com',
                        'name' => 'John Doe',
                        'tags' => ['newsletter', 'customer'],
                    ]
                ),
                $this->buildRequest(
                    'Update Contact',
                    'PATCH',
                    'api/v1/contacts/:contactId',
                    'Update an existing contact by ID. tags adds tag names. remove_tags removes tag names. A name in both fields returns 422.',
                    [
                        'name' => 'Jane Doe',
                        'tags' => ['vip'],
                        'remove_tags' => ['trial'],
                    ]
                ),
                $this->buildRequest(
                    'Unsubscribe Contact',
                    'POST',
                    'api/v1/contacts/:contactId/unsubscribe',
                    'Unsubscribe a contact. The contact is marked as unsubscribed and will no longer receive emails.'
                ),
                $this->buildRequest(
                    'Subscribe Contact',
                    'POST',
                    'api/v1/contacts/:contactId/subscribe',
                    'Clear an unsubscribe that an admin or the API made. A contact that unsubscribed through an email link gets 422. A suppressed address gets 422. To remove a bounce suppression of your client, send remove_suppression as true.'
                ),
                $this->buildRequest(
                    'Remove Contact from List',
                    'DELETE',
                    'api/v1/lists/:listId/contacts/:contactId',
                    'Remove a contact from a specific list. The contact is not deleted, only disassociated from the list.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listsFolder(): array
    {
        return [
            'name' => 'Lists',
            'description' => 'Manage mailing lists',
            'item' => [
                $this->buildRequest(
                    'Get All Lists',
                    'GET',
                    'api/v1/lists',
                    'Retrieve all mailing lists for the authenticated client. Cursor-paginated (per_page up to 100, cursor for next/previous page; response includes meta.next_cursor / meta.prev_cursor).'
                ),
                $this->buildRequest(
                    'Create List',
                    'POST',
                    'api/v1/lists',
                    'Create a new mailing list.',
                    [
                        'name' => 'My Newsletter List',
                    ]
                ),
                $this->buildRequest(
                    'Update List',
                    'PATCH',
                    'api/v1/lists/:listId',
                    'Update an existing mailing list.',
                    [
                        'name' => 'Updated List Name',
                    ]
                ),
                $this->buildRequest(
                    'Delete List',
                    'DELETE',
                    'api/v1/lists/:listId',
                    'Delete a mailing list.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tagsFolder(): array
    {
        return [
            'name' => 'Tags',
            'description' => 'Manage contact tags',
            'item' => [
                $this->buildRequest(
                    'Get All Tags',
                    'GET',
                    'api/v1/tags',
                    'Returns all the tags of the client, newest first. This list has no pagination.'
                ),
                $this->buildRequest(
                    'Create Tag',
                    'POST',
                    'api/v1/tags',
                    'Create a new tag.',
                    [
                        'name' => 'newsletter',
                    ]
                ),
                $this->buildRequest(
                    'Update Tag',
                    'PATCH',
                    'api/v1/tags/:tag',
                    'Rename a tag. When a saved filter of a newsletter that can still send, a sequence, a sequence step or a segment uses the tag, the request fails with 422.',
                    [
                        'name' => 'updated-tag',
                    ]
                ),
                $this->buildRequest(
                    'Delete Tag',
                    'DELETE',
                    'api/v1/tags/:tag',
                    'Delete a tag and remove it from each contact. The removal runs the live tag automations. When a saved filter uses the tag, the request fails with 422.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blocksFolder(): array
    {
        return [
            'name' => 'Blocks',
            'description' => 'Manage reusable HTML content blocks',
            'item' => [
                $this->buildRequest(
                    'Get All Blocks',
                    'GET',
                    'api/v1/blocks',
                    'Retrieve all blocks for the authenticated client. Cursor-paginated (per_page up to 100, cursor for next/previous page; response includes meta.next_cursor / meta.prev_cursor).'
                ),
                $this->buildRequest(
                    'Get Block',
                    'GET',
                    'api/v1/blocks/:blockId',
                    'Retrieve a single block by ID.'
                ),
                $this->buildRequest(
                    'Create Block',
                    'POST',
                    'api/v1/blocks',
                    'Create a new reusable HTML content block. The name must be lowercase with underscores only. The html_content is validated for safe HTML — script tags, iframes, event handlers, and other unsafe elements are rejected.',
                    [
                        'name' => 'footer_links',
                        'html_content' => '<div><a href="https://example.com">Home</a></div>',
                    ]
                ),
                $this->buildRequest(
                    'Update Block',
                    'PATCH',
                    'api/v1/blocks/:blockId',
                    'Update an existing block. The html_content is validated for safe HTML.',
                    [
                        'name' => 'updated_footer',
                        'html_content' => '<div><a href="https://example.com">Updated Home</a></div>',
                    ]
                ),
                $this->buildRequest(
                    'Delete Block',
                    'DELETE',
                    'api/v1/blocks/:blockId',
                    'Delete a block.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newslettersFolder(): array
    {
        return [
            'name' => 'Newsletters',
            'description' => 'Manage newsletters',
            'item' => [
                $this->buildRequest(
                    'List Newsletters',
                    'GET',
                    'api/v1/newsletters',
                    'Retrieve newsletters for the authenticated client. Cursor-paginated (per_page, cursor). Filter by ?status=draft|scheduled|queued|ab_testing|sent|failed, ?search=<title or subject>, and ?project=<projectId> (only newsletters assigned to that project). Response omits html_content/text_content (use show endpoint for full body) and includes metrics, total_contacts_reached, ab_test_enabled, content_variables, project_ids, and meta.next_cursor / meta.prev_cursor.'
                ),
                $this->buildRequest(
                    'Get Newsletter',
                    'GET',
                    'api/v1/newsletters/:newsletterId',
                    'Retrieve a single newsletter by ID. Response includes metrics, total_contacts_reached, ab_test_enabled, A/B side fields, content_variables, segment_id (read-only — set when targeting a saved segment), and filters (the segment\'s resolved audience when segment_id is set).'
                ),
                $this->buildRequest(
                    'Create Newsletter — Simple Draft',
                    'POST',
                    'api/v1/newsletters',
                    'Minimal draft creation. Title defaults to "Untitled newsletter" when omitted.',
                    [
                        'title' => 'March Product Update',
                        'subject' => 'What\'s new in March',
                        'preview_text' => 'Three new things, two hot takes, one demo',
                        'html_content' => '<h1>March Update</h1><p>Here is what is new...</p>',
                        'status' => 'draft',
                    ]
                ),
                $this->buildRequest(
                    'Create Newsletter — With Filters',
                    'POST',
                    'api/v1/newsletters',
                    'Create a newsletter targeted to a contact segment. See the Filters reference for full schema. Tags use names; lists/topics use numeric IDs; attributes use {key, operator, value} objects.',
                    [
                        'title' => 'VIP Promo',
                        'subject' => 'Exclusive offer for our top customers',
                        'html_content' => '<p>You unlocked it.</p>',
                        'status' => 'draft',
                        'filters' => [
                            ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip', 'premium']],
                            ['connector' => 'and', 'category' => 'lists', 'mode' => 'all', 'values' => [1]],
                            ['connector' => 'and', 'category' => 'attributes', 'mode' => 'all', 'values' => [
                                ['key' => 'age', 'operator' => 'gt', 'value' => '21'],
                            ]],
                        ],
                    ]
                ),
                $this->buildRequest(
                    'Create Newsletter — With Date / Engagement / Location Filters',
                    'POST',
                    'api/v1/newsletters',
                    'Date filters target the contact signup date (before/after/in_last/more_than/between). Engagement filters target newsletter opens/clicks/receipt (newsletter_id null = any newsletter). Location filters target country or timezone.',
                    [
                        'title' => 'Re-engagement',
                        'subject' => 'We miss you',
                        'html_content' => '<p>Come back.</p>',
                        'status' => 'draft',
                        'filters' => [
                            ['connector' => 'and', 'category' => 'date', 'operator' => 'more_than', 'values' => ['days' => 14]],
                            ['connector' => 'and', 'category' => 'engagement', 'operator' => 'not_opened', 'values' => ['newsletter_id' => null, 'within' => 'last_n_days', 'days' => 90]],
                            ['connector' => 'and', 'category' => 'location', 'field' => 'country', 'mode' => 'any', 'values' => ['US', 'GB']],
                        ],
                    ]
                ),
                $this->buildRequest(
                    'Create Newsletter — With A/B Test',
                    'POST',
                    'api/v1/newsletters',
                    'When ab_test_enabled is true, you must include subject_b and html_content_b for variant B. The system stores both variants as separate Message rows (variant=a and variant=b) and the dispatcher splits the audience for the test phase.',
                    [
                        'title' => 'Subject Line Test',
                        'status' => 'draft',
                        'ab_test_enabled' => true,
                        'subject' => 'Variant A: Save 20% today',
                        'preview_text' => 'Inbox preheader for variant A',
                        'html_content' => '<p>Variant A copy</p>',
                        'subject_b' => 'Variant B: Your 20% off ends tonight',
                        'preview_text_b' => 'Inbox preheader for variant B',
                        'html_content_b' => '<p>Variant B copy</p>',
                    ]
                ),
                $this->buildRequest(
                    'Create Newsletter — With Template + Variables',
                    'POST',
                    'api/v1/newsletters',
                    'Use a saved email template plus content_variables as defaults for {{ variable }} placeholders rendered in the email. List available templates via GET /api/email-templates and resolve the default with GET /api/email-templates/default.',
                    [
                        'title' => 'Welcome Drip',
                        'subject' => 'Welcome, {{ first_name }}!',
                        'html_content' => '<p>Hi {{ first_name }}, welcome to {{ company }}.</p>',
                        'status' => 'draft',
                        'email_template_id' => 1,
                        'content_variables' => [
                            'first_name' => 'Friend',
                            'company' => 'Acme Inc.',
                        ],
                    ]
                ),
                $this->buildRequest(
                    'Create Newsletter — Assigned to Projects',
                    'POST',
                    'api/v1/newsletters',
                    'Attach the newsletter to one or more projects via project_ids (numeric project IDs owned by the client). Also accepted on PATCH — passing project_ids replaces the full set; an empty array detaches all projects.',
                    [
                        'title' => 'Q3 Launch Announcement',
                        'subject' => 'The Q3 launch is here',
                        'html_content' => '<p>Say hello to the new lineup.</p>',
                        'status' => 'draft',
                        'project_ids' => [1, 2],
                    ]
                ),
                $this->buildRequest(
                    'Create Newsletter — Schedule For Later',
                    'POST',
                    'api/v1/newsletters',
                    'Send status=live with a future UTC scheduled_at. The newsletter is stored as "scheduled" and dispatched by the scheduler when due. Requires a verified email_identity_id. scheduled_at must be an ISO 8601 datetime in UTC.',
                    [
                        'title' => 'Black Friday Tease',
                        'subject' => 'Doors open Friday',
                        'html_content' => '<p>Mark your calendar.</p>',
                        'status' => 'live',
                        'scheduled_at' => '2026-11-26T08:00:00Z',
                        'email_identity_id' => 1,
                    ]
                ),
                $this->buildRequest(
                    'Create Newsletter — Send Now',
                    'POST',
                    'api/v1/newsletters',
                    'Send immediately. status=live requires scheduled_at — pass the current UTC ISO 8601 timestamp to dispatch right away. Requires a verified email_identity_id and the user must have SES bulk send permissions.',
                    [
                        'title' => 'Hotfix announcement',
                        'subject' => 'Service restored',
                        'html_content' => '<p>All systems normal.</p>',
                        'status' => 'live',
                        'scheduled_at' => '{{$isoTimestamp}}',
                        'email_identity_id' => 1,
                    ]
                ),
                $this->buildRequest(
                    'Update Newsletter',
                    'PATCH',
                    'api/v1/newsletters/:newsletterId',
                    'Update a draft or scheduled newsletter. Queued, sent, ab_testing, or failed newsletters return 422 with status validation error. All fields are optional but at least one must be provided. To go live, supply enough data so the result has subject, html_content, and a verified email_identity_id (from this request or already on record). scheduled_at is rejected unless status is "live".',
                    [
                        'title' => 'Updated March Product Update',
                        'subject' => 'Updated: What\'s new in March',
                        'html_content' => '<h1>March Update</h1><p>Updated content...</p>',
                        'status' => 'draft',
                    ]
                ),
                $this->buildRequest(
                    'Delete Newsletter',
                    'DELETE',
                    'api/v1/newsletters/:newsletterId',
                    'Delete a draft or scheduled newsletter. Queued, sent, ab_testing, or failed newsletters return 422.'
                ),
                $this->buildRequest(
                    'Send Test Email',
                    'POST',
                    'api/v1/newsletters/:newsletterId/test-mail',
                    'Send a test version of the newsletter to the authenticated user\'s email. Requires the newsletter to have a sender (email_identity_id on its message).'
                ),
                $this->buildRequest(
                    'Pick A/B Winner',
                    'POST',
                    'api/v1/newsletters/:newsletterId/ab-winner',
                    'Choose the winning A/B variant after the test phase. Only available while the newsletter is in "ab_testing" status. Marks the newsletter "sent", stores ab_test_winner_variant and fires the newsletter.sent webhook event one time.',
                    [
                        'winner_variant' => 'a',
                    ]
                ),
                $this->buildRequest(
                    'Preview Recipient Count',
                    'POST',
                    'api/v1/newsletters/contacts/preview',
                    'Returns the number of contacts that would receive a newsletter with the given filters. Useful for validating audience size before going live.',
                    [
                        'filters' => [
                            ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
                        ],
                    ]
                ),
                $this->buildRequest(
                    'Get Detected Variables',
                    'GET',
                    'api/v1/newsletters/:newsletterId/variables',
                    'Returns the list of {{ variables }} detected in the newsletter\'s subject and HTML body, plus the current content_variables defaults map. Reserved names (content, unsubscribe_url, preferences_url, opt_in_url) are excluded.'
                ),
                $this->buildRequest(
                    'Update Newsletter Variables',
                    'PATCH',
                    'api/v1/newsletters/:newsletterId/variables',
                    'Replace the content variable defaults of a draft or scheduled newsletter. The object replaces all saved defaults, and an empty object clears them. Each default is text of up to 255 characters, the same rule as the dashboard. A default can name a variable that the content does not use yet. A newsletter that is sending or sent cannot be changed.',
                    [
                        'content_variables' => [
                            'company' => 'Acme',
                            'first_name' => 'Friend',
                        ],
                    ]
                ),
            ],
        ];
    }

    /**
     * Add the link report requests of a newsletter or a sequence step to its folder.
     *
     * @param  array<string, mixed>  $folder
     * @return array<string, mixed>
     */
    private function withLinkRequests(array $folder, string $resource, string $path, string $subject, string $exampleName, string $exampleUrl): array
    {
        $folder['item'] = [
            ...$folder['item'],
            $this->buildRequest(
                "Get {$resource} Links",
                'GET',
                "{$path}/links",
                "List the links of {$subject} with their clicks: link key, link name, URL, unique contacts, total clicks and click rate (unique contacts / delivered * 100, null when no mail is delivered). A named link (data-bm-link attribute) groups by its link name. Any other link groups by its URL without utm_* parameters and without the #fragment. Clicks can include link scanners, for example Microsoft Safe Links."
            ),
            $this->buildRequest(
                "List {$resource} Link Contacts (named link)",
                'GET',
                "{$path}/links/contacts",
                "List the contacts who clicked one link of {$subject}, cursor-paginated (per_page 1-200, default 50, cursor). The link parameter is required and must be a link key from the link list. A named link has the key name:<link name>.",
                query: ['link' => "name:{$exampleName}", 'per_page' => '50'],
            ),
            $this->buildRequest(
                "List {$resource} Link Contacts (URL link)",
                'GET',
                "{$path}/links/contacts",
                "List the contacts who clicked an unnamed link of {$subject}. An unnamed link has the key url:<url>, with the URL from the link list.",
                query: ['link' => "url:{$exampleUrl}"],
            ),
        ];

        return $folder;
    }

    /**
     * @return array<string, mixed>
     */
    private function emailIdentitiesFolder(): array
    {
        return [
            'name' => 'Email Identities',
            'description' => 'Inspect verified sender identities',
            'item' => [
                $this->buildRequest(
                    'List Email Identities',
                    'GET',
                    'api/v1/email-identities',
                    'Retrieve all email identities for the authenticated client. Cursor-paginated (per_page up to 100, cursor for next/previous page; response includes meta.next_cursor / meta.prev_cursor). Filter by ?identity_status=verified|pending|failed.'
                ),
                $this->buildRequest(
                    'Get Email Identity',
                    'GET',
                    'api/v1/email-identities/:emailIdentityId',
                    'Retrieve a single email identity by ID.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailTemplatesFolder(): array
    {
        return [
            'name' => 'Email Templates',
            'description' => 'Browse saved email templates and the account default',
            'item' => [
                $this->buildRequest(
                    'List Email Templates',
                    'GET',
                    'api/v1/email-templates',
                    'Retrieve email templates for the authenticated client. Cursor-paginated (per_page up to 100, cursor for next/previous page; response includes meta.next_cursor / meta.prev_cursor). Returns active templates by default; pass ?archived=true for archived templates. Each item exposes is_default and is_system flags.'
                ),
                $this->buildRequest(
                    'Get Default Template',
                    'GET',
                    'api/v1/email-templates/default',
                    'Retrieve the active default email template for the authenticated client. Returns {"data": null} when none is set.'
                ),
                $this->buildRequest(
                    'Get Email Template',
                    'GET',
                    'api/v1/email-templates/:emailTemplateId',
                    'Retrieve a single email template by ID.'
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailLogsFolder(): array
    {
        return [
            'name' => 'Email Logs',
            'description' => 'View SES API request and response logs',
            'item' => [
                $this->buildRequest(
                    'Get All Email Logs',
                    'GET',
                    'api/v1/email-logs',
                    'Retrieve a cursor-paginated list of email logs for the authenticated account. Supports filtering by status, operation, recipient. Pagination via per_page and cursor (meta.next_cursor / meta.prev_cursor).'
                ),
                $this->buildRequest(
                    'Get Email Log',
                    'GET',
                    'api/v1/email-logs/:emailLogId',
                    'Retrieve a single email log entry by ID.'
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function buildRequest(string $name, string $method, string $path, string $description, ?array $body = null, array $query = []): array
    {
        $pathSegments = explode('/', $path);
        $variables = [];

        foreach ($pathSegments as $segment) {
            if (str_starts_with($segment, ':')) {
                $key = substr($segment, 1);
                $variables[] = [
                    'key' => $key,
                    'value' => '1',
                    'description' => 'The '.$this->humanize($key),
                ];
            }
        }

        $request = [
            'name' => $name,
            'request' => [
                'method' => $method,
                'header' => [
                    [
                        'key' => 'Accept',
                        'value' => 'application/json',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'Content-Type',
                        'value' => 'application/json',
                        'type' => 'text',
                    ],
                ],
                'url' => [
                    'raw' => '{{base_url}}/'.$path,
                    'host' => ['{{base_url}}'],
                    'path' => $pathSegments,
                ],
                'description' => $description,
            ],
            'response' => [],
        ];

        if (! empty($variables)) {
            $request['request']['url']['variable'] = $variables;
        }

        if ($query !== []) {
            $request['request']['url']['raw'] .= '?'.collect($query)->map(fn (string $value, string $key): string => "{$key}={$value}")->implode('&');
            $request['request']['url']['query'] = collect($query)->map(fn (string $value, string $key): array => ['key' => $key, 'value' => $value])->values()->all();
        }

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $request['request']['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'options' => [
                    'raw' => [
                        'language' => 'json',
                    ],
                ],
            ];
        }

        return $request;
    }

    private function humanize(string $key): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', str_replace('Id', ' ID', $key)));
    }
}
