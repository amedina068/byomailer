<?php

namespace Tests\Feature;

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
