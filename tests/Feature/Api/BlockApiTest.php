<?php

namespace Tests\Feature\Api;

use App\Models\Block;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlockApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_can_be_listed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherBlock = Block::factory()->create();
        Block::factory()->for($user)->create(['name' => 'my_block']);

        $response = $this->getJson('/api/v1/blocks');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'my_block')
            ->assertJsonMissing(['name' => $otherBlock->name]);
    }

    public function test_block_can_be_shown(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $block = Block::factory()->for($user)->create(['name' => 'header']);

        $response = $this->getJson("/api/v1/blocks/{$block->id}");

        $response->assertOk()
            ->assertJsonPath('data.name', 'header')
            ->assertJsonPath('data.html_content', $block->html_content);
    }

    public function test_block_can_be_created(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'footer_links',
            'html_content' => '<p>Footer content</p>',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'footer_links')
            ->assertJsonPath('data.html_content', '<p>Footer content</p>');

        $this->assertDatabaseHas('blocks', [
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'footer_links',
        ]);
    }

    public function test_block_can_be_updated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $block = Block::factory()->for($user)->create(['name' => 'original']);

        $response = $this->patchJson("/api/v1/blocks/{$block->id}", [
            'name' => 'updated',
            'html_content' => '<h1>Updated</h1>',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'updated')
            ->assertJsonPath('data.html_content', '<h1>Updated</h1>');

        $this->assertDatabaseHas('blocks', [
            'id' => $block->id,
            'name' => 'updated',
        ]);
    }

    public function test_block_can_be_deleted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $block = Block::factory()->for($user)->create();

        $response = $this->deleteJson("/api/v1/blocks/{$block->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('blocks', ['id' => $block->id]);
    }

    public function test_block_creation_requires_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => '',
            'html_content' => '<p>Content</p>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_block_creation_requires_html_content(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'test_block',
            'html_content' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('html_content');
    }

    public function test_block_name_must_be_lowercase_with_underscores(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'Invalid Name',
            'html_content' => '<p>Content</p>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_block_name_must_be_unique_per_client(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        Block::factory()->for($user)->create(['name' => 'existing']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'existing',
            'html_content' => '<p>Content</p>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_user_cannot_access_other_users_blocks(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherBlock = Block::factory()->for($otherUser)->create();

        $showResponse = $this->getJson("/api/v1/blocks/{$otherBlock->id}");
        $showResponse->assertNotFound();

        $updateResponse = $this->patchJson("/api/v1/blocks/{$otherBlock->id}", [
            'name' => 'hacked',
            'html_content' => '<p>Hacked</p>',
        ]);
        $updateResponse->assertNotFound();

        $deleteResponse = $this->deleteJson("/api/v1/blocks/{$otherBlock->id}");
        $deleteResponse->assertNotFound();
    }

    public function test_html_content_rejects_script_tags(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'xss_block',
            'html_content' => '<p>Hello</p><script>alert("xss")</script>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('html_content');
    }

    public function test_html_content_rejects_iframe_tags(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'iframe_block',
            'html_content' => '<iframe src="https://evil.com"></iframe>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('html_content');
    }

    public function test_html_content_rejects_event_handlers(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'event_block',
            'html_content' => '<img src="x" onerror="alert(1)">',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('html_content');
    }

    public function test_html_content_rejects_javascript_urls(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'js_url_block',
            'html_content' => '<a href="javascript:alert(1)">Click</a>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('html_content');
    }

    public function test_html_content_allows_safe_html(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $safeHtml = '<div><h1>Welcome</h1><p>This is <strong>safe</strong> content with <a href="https://example.com">links</a> and <img src="https://example.com/image.png" alt="image">.</p></div>';

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'safe_block',
            'html_content' => $safeHtml,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.html_content', $safeHtml);
    }

    public function test_different_clients_can_use_same_block_name(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Sanctum::actingAs($userB, ['*']);
        Block::factory()->for($userA)->create(['name' => 'shared']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'shared',
            'html_content' => '<p>Content</p>',
        ]);

        $response->assertCreated();
    }

    public function test_html_content_rejects_mismatched_tags(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/blocks', [
            'name' => 'invalid_html',
            'html_content' => '<div><p>Mismatched</div></p>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('html_content');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/blocks');

        $response->assertUnauthorized();
    }
}
