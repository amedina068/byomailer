<?php

namespace Tests\Feature\Api;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_can_be_listed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherTag = Tag::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'Mine']);

        $response = $this->getJson('/api/v1/tags');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine')
            ->assertJsonMissing(['name' => $otherTag->name]);
    }

    public function test_a_teammate_lists_the_tags_of_the_client(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        Tag::factory()->for($owner)->create(['name' => 'VIP']);
        Sanctum::actingAs($teammate, ['*']);

        $response = $this->getJson('/api/v1/tags');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'VIP');
    }

    public function test_tag_can_be_created(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/tags', ['name' => 'VIP']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'VIP');

        $this->assertDatabaseHas('tags', [
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'name' => 'VIP',
        ]);
    }

    public function test_tag_can_be_updated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $tag = Tag::factory()->for($user)->create(['name' => 'Original']);

        $response = $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Updated']);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated');

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Updated',
        ]);
    }

    public function test_a_tag_can_keep_its_own_name_on_update(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);

        $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'VIP'])
            ->assertOk()
            ->assertJsonPath('data.name', 'VIP');
    }

    public function test_tag_can_be_deleted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $tag = Tag::factory()->for($user)->create();

        $response = $this->deleteJson("/api/v1/tags/{$tag->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_a_teammate_can_update_and_delete_a_tag_of_the_client(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        $tag = Tag::factory()->for($owner)->create(['name' => 'VIP']);
        Sanctum::actingAs($teammate, ['*']);

        $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Gold'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Gold');

        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_tag_creation_requires_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/tags', ['name' => '']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'The name field is required.']);
    }

    public function test_a_teammate_cannot_create_a_tag_name_that_the_client_has(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        Tag::factory()->for($owner)->create(['name' => 'Existing']);
        Sanctum::actingAs($teammate, ['*']);

        $response = $this->postJson('/api/v1/tags', ['name' => 'Existing']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'The name has already been taken.']);
    }

    public function test_a_teammate_cannot_rename_a_tag_to_a_name_that_the_client_has(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        Tag::factory()->for($owner)->create(['name' => 'Existing']);
        $tag = Tag::factory()->for($teammate)->create(['name' => 'Other']);
        Sanctum::actingAs($teammate, ['*']);

        $response = $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Existing']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'The name has already been taken.']);
    }

    public function test_a_tag_of_another_client_is_not_found(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherTag = Tag::factory()->for($otherUser)->create(['name' => 'Theirs']);

        $this->patchJson("/api/v1/tags/{$otherTag->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/v1/tags/{$otherTag->id}")->assertNotFound();

        $this->assertDatabaseHas('tags', ['id' => $otherTag->id, 'name' => 'Theirs']);
    }

    public function test_a_tag_id_that_is_not_a_number_is_not_found(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/v1/tags/abc', ['name' => 'VIP'])->assertNotFound();
        $this->deleteJson('/api/v1/tags/abc')->assertNotFound();
    }
}
