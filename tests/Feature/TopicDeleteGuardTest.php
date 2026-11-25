<?php

namespace Tests\Feature;

use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisualAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopicDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailList $list;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->list = MailList::factory()->for($this->user)->create();
        $this->topic = Topic::factory()->for($this->user)->create(['list_id' => $this->list->id]);
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array<string, mixed>>
     */
    private function topicFilters(array $ids, string $mode = 'any'): array
    {
        return [['connector' => 'and', 'category' => 'topics', 'mode' => $mode, 'values' => $ids]];
    }

    private function apiDestroy(): TestResponse
    {
        Sanctum::actingAs($this->user, ['*']);

        return $this->deleteJson("/api/v1/lists/{$this->list->id}/topics/{$this->topic->id}");
    }

    private function assertApiDestroyIsBlockedBy(string $name): void
    {
        $response = $this->apiDestroy();

        $response->assertUnprocessable()->assertJsonValidationErrors('topic');
        $this->assertStringContainsString("\"{$name}\"", $response->json('errors.topic.0'));
        $this->assertDatabaseHas('topics', ['id' => $this->topic->id]);
    }

    public function test_api_destroy_is_blocked_while_a_draft_newsletter_filters_by_the_topic(): void
    {
        Newsletter::factory()->for($this->user)
            ->withFilters($this->topicFilters([$this->topic->id], 'none'))
            ->create(['status' => 'draft', 'title' => 'Spring sale']);

        $this->assertApiDestroyIsBlockedBy('Spring sale');
    }

    public function test_api_destroy_is_blocked_while_a_sequence_filters_by_the_topic(): void
    {
        Sequence::factory()->for($this->user)
            ->withFilters($this->topicFilters([(string) $this->topic->id]))
            ->create(['name' => 'Welcome']);

        $this->assertApiDestroyIsBlockedBy('Welcome');
    }

    public function test_api_destroy_is_blocked_while_a_sequence_step_filters_by_the_topic(): void
    {
        $sequence = Sequence::factory()->for($this->user)->create(['name' => 'Onboarding']);
        SequenceStep::factory()->for($sequence)->create(['filters' => $this->topicFilters([$this->topic->id])]);

        $this->assertApiDestroyIsBlockedBy('Onboarding');
    }

    public function test_api_destroy_is_blocked_while_a_segment_filters_by_the_topic(): void
    {
        Segment::factory()
            ->withFilters($this->topicFilters([$this->topic->id]))
            ->create(['client_id' => $this->user->client_id, 'name' => 'Readers']);

        $this->assertApiDestroyIsBlockedBy('Readers');
    }

    public function test_api_destroy_is_blocked_while_a_visual_automation_checks_the_topic(): void
    {
        VisualAutomation::factory()->for($this->user)->create([
            'name' => 'Branch on topic',
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'tag_added', 'value' => 999]],
                    ['id' => 'condition-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 100], 'data' => ['condition_type' => 'subscribed_to_topic', 'value' => $this->topic->id]],
                ],
                'edges' => [],
            ],
        ]);

        $this->assertApiDestroyIsBlockedBy('Branch on topic');
    }

    public function test_api_destroy_is_blocked_while_a_visual_automation_exit_condition_uses_the_topic(): void
    {
        VisualAutomation::factory()->for($this->user)->create([
            'name' => 'Exit on topic',
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'tag_added', 'value' => 999]],
                ],
                'edges' => [],
                'exit_conditions' => [['type' => 'subscribed_to_topic', 'value' => $this->topic->id]],
            ],
        ]);

        $this->assertApiDestroyIsBlockedBy('Exit on topic');
    }

    public function test_api_destroy_is_allowed_when_only_sent_newsletters_and_the_list_are_used(): void
    {
        Newsletter::factory()->for($this->user)
            ->withFilters($this->topicFilters([$this->topic->id]))
            ->create(['status' => 'sent']);
        Newsletter::factory()->for($this->user)
            ->withFilters([['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$this->topic->id, $this->list->id]]])
            ->create(['status' => 'draft']);

        $this->apiDestroy()->assertNoContent();

        $this->assertDatabaseMissing('topics', ['id' => $this->topic->id]);
    }

    public function test_web_destroy_is_blocked_while_a_newsletter_filters_by_the_topic(): void
    {
        Newsletter::factory()->for($this->user)
            ->withFilters($this->topicFilters([$this->topic->id]))
            ->create(['status' => 'scheduled', 'title' => 'Weekly digest']);

        $response = $this->actingAs($this->user)
            ->delete(route('lists.topics.destroy', ['list' => $this->list->id, 'topic' => $this->topic->id]));

        $response->assertRedirect(route('lists.show', $this->list->id));
        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, '"Weekly digest"'));
        $this->assertDatabaseHas('topics', ['id' => $this->topic->id]);
    }

    public function test_web_destroy_deletes_an_unused_topic(): void
    {
        $response = $this->actingAs($this->user)
            ->delete(route('lists.topics.destroy', ['list' => $this->list->id, 'topic' => $this->topic->id]));

        $response->assertRedirect(route('lists.show', $this->list->id));
        $response->assertSessionMissing('error');
        $this->assertDatabaseMissing('topics', ['id' => $this->topic->id]);
    }
}
