<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiSpecTest extends TestCase
{
    private const TAGS = [
        'Contacts', 'Lists', 'Topics', 'Tags', 'Newsletters', 'Sequences', 'Blocks',
        'Email templates', 'Email identities', 'Email logs', 'Projects', 'Webhooks',
    ];

    public function test_openapi_spec_is_served(): void
    {
        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $body = $response->json();

        $this->assertSame('1.0.0', $body['info']['version'] ?? null);
        $this->assertNotEmpty($body['paths'] ?? []);
    }

    public function test_openapi_spec_covers_critical_v1_endpoints(): void
    {
        $body = $this->get('/docs/api.json')->json();
        $paths = array_keys($body['paths'] ?? []);

        $expected = [
            '/newsletters',
            '/newsletters/{newsletterId}',
            '/newsletters/{newsletterId}/test-mail',
            '/newsletters/{newsletterId}/ab-winner',
            '/newsletters/{newsletterId}/variables',
            '/newsletters/contacts/preview',
            '/email-identities',
            '/email-identities/{emailIdentityId}',
            '/email-templates',
            '/email-templates/default',
            '/email-templates/{emailTemplateId}',
            '/contacts',
            '/contacts/{contactId}',
            '/projects',
            '/projects/{projectId}',
            '/lists',
            '/tags',
            '/blocks',
            '/email-logs',
        ];

        foreach ($expected as $path) {
            $this->assertContains($path, $paths, "OpenAPI spec missing path: {$path}");
        }
    }

    public function test_legacy_redirect_not_documented(): void
    {
        $body = $this->get('/docs/api.json')->json();

        $this->assertArrayNotHasKey('/{any}', $body['paths'] ?? []);
    }

    public function test_the_document_requires_a_bearer_token(): void
    {
        $body = $this->get('/docs/api.json')->json();

        $this->assertSame('http', $body['components']['securitySchemes']['http']['type'] ?? null);
        $this->assertSame('bearer', $body['components']['securitySchemes']['http']['scheme'] ?? null);
        $this->assertSame([['http' => []]], $body['security'] ?? null);
    }

    public function test_the_tags_are_the_resource_groups_in_order(): void
    {
        $body = $this->get('/docs/api.json')->json();

        $this->assertSame(self::TAGS, array_column($body['tags'] ?? [], 'name'));

        foreach ($body['tags'] as $tag) {
            $this->assertNotEmpty($tag['description'] ?? null, "Tag {$tag['name']} has no description.");
        }
    }

    public function test_each_operation_has_a_summary_a_description_and_one_tag(): void
    {
        foreach ($this->operations() as $name => $operation) {
            $this->assertNotEmpty($operation['summary'] ?? null, "{$name} has no summary.");
            $this->assertNotEmpty($operation['description'] ?? null, "{$name} has no description.");
            $this->assertCount(1, $operation['tags'] ?? [], "{$name} must have one tag.");
            $this->assertContains($operation['tags'][0], self::TAGS, "{$name} has an unknown tag.");
        }
    }

    public function test_each_operation_documents_its_error_responses(): void
    {
        foreach ($this->operations() as $name => $operation) {
            $codes = array_map('strval', array_keys($operation['responses']));

            $this->assertContains('401', $codes, "{$name} does not document 401.");
            $this->assertContains('403', $codes, "{$name} does not document 403.");

            if (str_contains($name, '{')) {
                $this->assertContains('404', $codes, "{$name} does not document 404.");
            }

            // Each API route has the `api` rate limit.
            $this->assertContains('429', $codes, "{$name} does not document 429.");
            $this->assertArrayHasKey('Retry-After', $operation['responses']['429']['headers'] ?? []);
        }
    }

    public function test_each_operation_has_a_unique_operation_id(): void
    {
        $operationIds = array_column($this->operations(), 'operationId');

        foreach ($operationIds as $operationId) {
            $this->assertMatchesRegularExpression('/^v1\.[a-z-]+(\.[a-z-]+)+$/', $operationId);
        }

        $this->assertSame($operationIds, array_unique($operationIds));
    }

    public function test_each_cursor_list_documents_its_links_and_meta(): void
    {
        $lists = [
            'get /contacts', 'get /lists', 'get /newsletters', 'get /sequences', 'get /email-templates',
            'get /email-identities', 'get /email-logs', 'get /projects', 'get /webhook-endpoints',
            'get /webhook-endpoints/{webhookEndpointId}/deliveries',
        ];

        foreach ($lists as $name) {
            $schema = $this->operations()[$name]['responses']['200']['content']['application/json']['schema'];

            $this->assertSame(['data', 'links', 'meta'], $schema['required'], "{$name} must document links and meta.");
            $this->assertEqualsCanonicalizing(
                ['path', 'per_page', 'next_cursor', 'prev_cursor'],
                array_keys($schema['properties']['meta']['properties']),
                "{$name} must document the cursor meta.",
            );
        }
    }

    public function test_the_sequence_list_documents_the_status_values(): void
    {
        $status = collect($this->operations()['get /sequences']['parameters'])->firstWhere('name', 'status');

        $this->assertSame(['draft', 'live', 'paused'], $status['schema']['enum']);
    }

    public function test_the_sequence_list_and_the_sequence_record_have_one_shape_each(): void
    {
        $body = $this->get('/docs/api.json')->json();
        $list = $this->operations()['get /sequences']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame('#/components/schemas/SequenceSummaryResource', $list['properties']['data']['items']['$ref']);

        foreach (['SequenceSummaryResource', 'SequenceResource'] as $name) {
            $schema = $body['components']['schemas'][$name];

            $this->assertSame('object', $schema['type'] ?? null, "{$name} must have one shape.");

            foreach (['contacts_count', 'completed_count', 'active_count', 'steps_count'] as $count) {
                $this->assertSame('integer', $schema['properties'][$count]['type'], "{$name}.{$count} must be an integer.");
            }

            $this->assertSame('integer', $schema['properties']['project_ids']['items']['type'], "{$name}.project_ids must be integers.");
        }

        $this->assertArrayHasKey('steps', $body['components']['schemas']['SequenceResource']['properties']);
        $this->assertArrayNotHasKey('steps', $body['components']['schemas']['SequenceSummaryResource']['properties']);
    }

    public function test_the_ab_winner_does_not_promise_a_second_send(): void
    {
        $description = $this->operations()['post /newsletters/{newsletterId}/ab-winner']['description'];

        $this->assertStringNotContainsString('rest of the audience', $description);
        $this->assertStringContainsString('sends no more email', $description);
    }

    public function test_the_step_mail_export_documents_only_the_filters_that_it_reads(): void
    {
        $operation = $this->operations()['get /sequences/{sequenceId}/steps/{sequenceStepId}/contact-mails/export'];
        $names = array_column($operation['parameters'], 'name');

        $this->assertContains('current_only', $names);
        $this->assertContains('status', $names);

        foreach (['newsletter_id', 'sequence_id', 'step_id', 'page', 'per_page'] as $ignored) {
            $this->assertNotContains($ignored, $names);
        }
    }

    public function test_the_step_mail_export_marks_current_only_as_ignored(): void
    {
        $operation = $this->operations()['get /sequences/{sequenceId}/steps/{sequenceStepId}/contact-mails/export'];
        $currentOnly = collect($operation['parameters'])->firstWhere('name', 'current_only');

        $this->assertTrue($currentOnly['deprecated'] ?? $currentOnly['schema']['deprecated'] ?? false);
        $this->assertStringContainsString('full history', $currentOnly['description'] ?? $currentOnly['schema']['description'] ?? '');
    }

    public function test_the_subscribe_documents_its_422_fields(): void
    {
        $this->assertDocumentsValidationErrors('post /contacts/{contactId}/subscribe', ['contact', 'remove_suppression']);
    }

    public function test_the_newsletter_delete_documents_its_422_field(): void
    {
        $this->assertDocumentsValidationErrors('delete /newsletters/{newsletterId}', ['status']);
    }

    public function test_the_newsletter_test_mail_documents_its_422_fields(): void
    {
        $this->assertDocumentsValidationErrors('post /newsletters/{newsletterId}/test-mail', ['email_identity_id', 'send']);
    }

    /**
     * @param  list<string>  $fields
     */
    private function assertDocumentsValidationErrors(string $name, array $fields): void
    {
        $response = $this->operations()[$name]['responses']['422'] ?? null;

        $this->assertNotNull($response, "{$name} does not document 422.");

        $schema = $response['content']['application/json']['schema'];

        $this->assertSame(['message', 'errors'], $schema['required']);
        $this->assertEqualsCanonicalizing($fields, array_keys($schema['properties']['errors']['properties']));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function operations(): array
    {
        $body = $this->get('/docs/api.json')->json();

        $operations = [];

        foreach ($body['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $operations["{$method} {$path}"] = $operation;
            }
        }

        $this->assertCount(68, $operations);

        return $operations;
    }
}
