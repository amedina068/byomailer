<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_documentation_page_redirects_permanently_to_getting_started(): void
    {
        $response = $this->get(route('documentation'));

        $response->assertStatus(301);
        $response->assertRedirect(route('documentation.guides.getting-started'));
    }

    public function test_postman_collection_can_be_downloaded_by_guests(): void
    {
        $response = $this->get(route('documentation.postman'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDownload('byomailer-postman-collection.json');

        $content = json_decode($response->streamedContent(), true);
        $this->assertArrayHasKey('info', $content);
        $this->assertArrayHasKey('item', $content);
        $this->assertCount(12, $content['item']);
        $this->assertContains('Projects', array_column($content['item'], 'name'));
    }

    public function test_postman_link_contact_requests_carry_the_link_query_parameter(): void
    {
        $content = json_decode($this->get(route('documentation.postman'))->streamedContent(), true);

        $requests = collect($content['item'])
            ->flatMap(fn (array $folder): array => $folder['item'] ?? [])
            ->keyBy('name');

        $request = $requests['List Newsletter Link Contacts (named link)']['request'];

        $this->assertStringEndsWith('/links/contacts?link=name:black-friday&per_page=50', $request['url']['raw']);
        $this->assertSame(
            [['key' => 'link', 'value' => 'name:black-friday'], ['key' => 'per_page', 'value' => '50']],
            $request['url']['query'],
        );
        $this->assertSame(['api', 'v1', 'newsletters', ':newsletterId', 'links', 'contacts'], $request['url']['path']);
        $this->assertArrayNotHasKey('query', $requests['Get Newsletter Links']['request']['url']);
        $this->assertArrayHasKey('List Sequence Step Link Contacts (URL link)', $requests->all());
    }

    public function test_link_clicks_api_reference_page_can_be_viewed_by_guests(): void
    {
        $this->get(route('documentation.api.link-clicks'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('documentation/api/LinkClicks'));
    }

    public function test_release_notes_page_redirects_permanently_to_the_docs_site(): void
    {
        $response = $this->get(route('documentation.release-notes'));

        $response->assertStatus(301);
        $response->assertRedirect('https://byomailer.com/docs/getting-started/release-notes');
    }

    public function test_markdown_documentation_uses_the_app_url(): void
    {
        $response = $this->get(route('documentation.markdown'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'inline; filename="api-documentation.md"');

        $content = $response->getContent();

        $this->assertStringContainsString('# BYOMailer API Documentation', $content);
        $this->assertStringContainsString(rtrim(config('app.url'), '/').'/api/v1', $content);
        $this->assertStringNotContainsString('byomailer.example', $content);
    }

    public function test_openapi_download_uses_the_app_url(): void
    {
        $response = $this->get(route('documentation.openapi'));

        $response->assertOk();
        $this->assertStringContainsString(rtrim(config('app.url'), '/').'/api/v1', $response->getContent());
        $this->assertStringNotContainsString('byomailer.example', $response->getContent());
    }
}
