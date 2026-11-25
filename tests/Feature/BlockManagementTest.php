<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BlockManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_blocks_index(): void
    {
        $user = User::factory()->create();
        Block::factory()->for($user)->create(['name' => 'welcome_header']);

        $response = $this->actingAs($user)->get(route('blocks.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('blocks/Index')
            ->has('blocks', 1)
            ->where('blocks.0.name', 'welcome_header')
        );
    }

    public function test_user_can_view_create_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('blocks.create'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('blocks/Create')
        );
    }

    public function test_user_can_create_block(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('blocks.store'), [
            'name' => 'footer_links',
            'html_content' => '<p>Footer content</p>',
        ]);

        $response->assertRedirect(route('blocks.index'));

        $this->assertDatabaseHas('blocks', [
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'footer_links',
            'html_content' => '<p>Footer content</p>',
        ]);
    }

    public function test_block_name_must_be_lowercase_with_underscores(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('blocks.store'), [
            'name' => 'Invalid Name',
            'html_content' => '<p>Content</p>',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_block_name_must_be_unique_per_client(): void
    {
        $user = User::factory()->create();
        Block::factory()->for($user)->create(['name' => 'existing']);

        $response = $this->actingAs($user)->post(route('blocks.store'), [
            'name' => 'existing',
            'html_content' => '<p>Content</p>',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_different_clients_can_use_same_block_name(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Block::factory()->for($userA)->create(['name' => 'shared']);

        $response = $this->actingAs($userB)->post(route('blocks.store'), [
            'name' => 'shared',
            'html_content' => '<p>Content</p>',
        ]);

        $response->assertRedirect(route('blocks.index'));
    }

    public function test_user_can_view_edit_page(): void
    {
        $user = User::factory()->create();
        $block = Block::factory()->for($user)->create(['name' => 'header']);

        $response = $this->actingAs($user)->get(route('blocks.edit', $block));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('blocks/Edit')
            ->where('block.name', 'header')
        );
    }

    public function test_user_can_update_block(): void
    {
        $user = User::factory()->create();
        $block = Block::factory()->for($user)->create(['name' => 'header']);

        $response = $this->actingAs($user)->put(route('blocks.update', $block), [
            'name' => 'updated_header',
            'html_content' => '<h1>Updated</h1>',
        ]);

        $response->assertRedirect(route('blocks.index'));

        $this->assertDatabaseHas('blocks', [
            'id' => $block->id,
            'name' => 'updated_header',
            'html_content' => '<h1>Updated</h1>',
        ]);
    }

    public function test_user_can_delete_block(): void
    {
        $user = User::factory()->create();
        $block = Block::factory()->for($user)->create();

        $response = $this->actingAs($user)->delete(route('blocks.destroy', $block));

        $response->assertRedirect(route('blocks.index'));
        $this->assertDatabaseMissing('blocks', ['id' => $block->id]);
    }

    public function test_user_cannot_edit_another_clients_block(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $block = Block::factory()->for($userA)->create();

        $response = $this->actingAs($userB)->get(route('blocks.edit', $block));

        $response->assertNotFound();
    }

    public function test_user_cannot_delete_another_clients_block(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $block = Block::factory()->for($userA)->create();

        $response = $this->actingAs($userB)->delete(route('blocks.destroy', $block));

        $response->assertNotFound();
        $this->assertDatabaseHas('blocks', ['id' => $block->id]);
    }

    public function test_html_content_rejects_script_tags(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('blocks.store'), [
            'name' => 'xss_test',
            'html_content' => '<p>Safe</p><script>alert("xss")</script>',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_html_content_rejects_unsafe_html_on_update(): void
    {
        $user = User::factory()->create();
        $block = Block::factory()->for($user)->create();

        $response = $this->actingAs($user)->put(route('blocks.update', $block), [
            'name' => $block->name,
            'html_content' => '<img src="x" onerror="alert(1)">',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_html_content_rejects_invalid_html(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('blocks.store'), [
            'name' => 'bad_html',
            'html_content' => '<b>Bold<i>Italic</b>Done</i>',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_html_content_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('blocks.store'), [
            'name' => 'test',
            'html_content' => '',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_user_can_list_blocks_as_json(): void
    {
        $user = User::factory()->create();
        Block::factory()->for($user)->create(['name' => 'header']);
        Block::factory()->for($user)->create(['name' => 'footer']);

        $response = $this->actingAs($user)
            ->getJson(route('blocks.list'));

        $response->assertOk()
            ->assertJsonCount(2, 'blocks')
            ->assertJsonStructure([
                'blocks' => [
                    ['id', 'name'],
                ],
            ]);
    }

    public function test_list_only_returns_own_client_blocks(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Block::factory()->for($user)->create(['name' => 'mine']);
        Block::factory()->for($otherUser)->create(['name' => 'theirs']);

        $response = $this->actingAs($user)
            ->getJson(route('blocks.list'));

        $response->assertOk()
            ->assertJsonCount(1, 'blocks')
            ->assertJsonPath('blocks.0.name', 'mine');
    }
}
