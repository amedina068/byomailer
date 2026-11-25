<?php

namespace Tests\Feature\Api;

use App\Jobs\BackfillTopicSubscribersJob;
use App\Models\MailList;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_topics_can_be_listed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();
        Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'Mine A']);
        Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'Mine B']);

        $otherUser = User::factory()->create();
        $otherList = MailList::factory()->for($otherUser)->create();
        Topic::factory()->for($otherUser)->create(['list_id' => $otherList->id, 'name' => 'Other']);

        $response = $this->getJson("/api/v1/lists/{$list->id}/topics");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['name' => 'Other']);
    }

    public function test_topic_index_returns_404_for_other_users_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherList = MailList::factory()->for($otherUser)->create();

        $this->getJson("/api/v1/lists/{$otherList->id}/topics")
            ->assertNotFound();
    }

    public function test_topic_can_be_created_and_dispatches_backfill_job(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();

        $response = $this->postJson("/api/v1/lists/{$list->id}/topics", [
            'name' => 'Weekly Digest',
            'description' => 'Once-a-week roundup',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Weekly Digest')
            ->assertJsonPath('data.list_id', $list->id);

        $this->assertDatabaseHas('topics', [
            'list_id' => $list->id,
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'Weekly Digest',
            'description' => 'Once-a-week roundup',
        ]);

        Bus::assertDispatched(BackfillTopicSubscribersJob::class);
    }

    public function test_topic_creation_requires_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();

        $this->postJson("/api/v1/lists/{$list->id}/topics", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_topic_name_must_be_unique_per_list(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();
        Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'Existing']);

        $this->postJson("/api/v1/lists/{$list->id}/topics", ['name' => 'Existing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_topic_name_can_repeat_across_lists(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $listA = MailList::factory()->for($user)->create();
        $listB = MailList::factory()->for($user)->create();
        Topic::factory()->for($user)->create(['list_id' => $listA->id, 'name' => 'Shared']);

        $this->postJson("/api/v1/lists/{$listB->id}/topics", ['name' => 'Shared'])
            ->assertCreated();
    }

    public function test_topic_creation_returns_404_for_other_users_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherList = MailList::factory()->for($otherUser)->create();

        $this->postJson("/api/v1/lists/{$otherList->id}/topics", ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_topic_can_be_updated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();
        $topic = Topic::factory()->for($user)->create([
            'list_id' => $list->id,
            'name' => 'Original',
            'description' => 'Old',
        ]);

        $response = $this->patchJson("/api/v1/lists/{$list->id}/topics/{$topic->id}", [
            'name' => 'Updated',
            'description' => 'New',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated')
            ->assertJsonPath('data.description', 'New');

        $this->assertDatabaseHas('topics', [
            'id' => $topic->id,
            'name' => 'Updated',
            'description' => 'New',
        ]);
    }

    public function test_topic_update_ignores_self_for_unique_check(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();
        $topic = Topic::factory()->for($user)->create([
            'list_id' => $list->id,
            'name' => 'KeepName',
        ]);

        $this->patchJson("/api/v1/lists/{$list->id}/topics/{$topic->id}", [
            'name' => 'KeepName',
            'description' => 'Updated description',
        ])->assertOk();
    }

    public function test_topic_update_returns_404_for_other_users_topic(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherList = MailList::factory()->for($otherUser)->create();
        $otherTopic = Topic::factory()->for($otherUser)->create(['list_id' => $otherList->id]);

        $this->patchJson("/api/v1/lists/{$otherList->id}/topics/{$otherTopic->id}", [
            'name' => 'Hacked',
        ])->assertNotFound();
    }

    public function test_topic_can_be_deleted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $list = MailList::factory()->for($user)->create();
        $topic = Topic::factory()->for($user)->create(['list_id' => $list->id]);

        $this->deleteJson("/api/v1/lists/{$list->id}/topics/{$topic->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('topics', ['id' => $topic->id]);
    }

    public function test_topic_destroy_returns_404_for_other_users_topic(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $otherList = MailList::factory()->for($otherUser)->create();
        $otherTopic = Topic::factory()->for($otherUser)->create(['list_id' => $otherList->id]);

        $this->deleteJson("/api/v1/lists/{$otherList->id}/topics/{$otherTopic->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('topics', ['id' => $otherTopic->id]);
    }

    public function test_topic_endpoints_require_authentication(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $topic = Topic::factory()->for($user)->create(['list_id' => $list->id]);

        $this->getJson("/api/v1/lists/{$list->id}/topics")->assertUnauthorized();
        $this->postJson("/api/v1/lists/{$list->id}/topics", ['name' => 'X'])->assertUnauthorized();
        $this->patchJson("/api/v1/lists/{$list->id}/topics/{$topic->id}", ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson("/api/v1/lists/{$list->id}/topics/{$topic->id}")->assertUnauthorized();
    }
}
