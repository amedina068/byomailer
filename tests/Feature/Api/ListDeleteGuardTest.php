<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
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

class ListDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->list = MailList::factory()->for($this->user)->create();
        Sanctum::actingAs($this->user, ['*']);
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array<string, mixed>>
     */
    private function filtersFor(string $category, array $ids, string $mode = 'any'): array
    {
        return [['connector' => 'and', 'category' => $category, 'mode' => $mode, 'values' => $ids]];
    }

    private function destroy(): TestResponse
    {
        return $this->deleteJson("/api/v1/lists/{$this->list->id}");
    }

    private function assertDestroyIsBlockedBy(string $name): void
    {
        $response = $this->destroy();

        $response->assertUnprocessable()->assertJsonValidationErrors('list');
        $this->assertStringContainsString("\"{$name}\"", $response->json('errors.list.0'));
        $this->assertDatabaseHas('lists', ['id' => $this->list->id]);
    }

    public function test_destroy_is_blocked_while_a_draft_newsletter_filters_by_the_list(): void
    {
        Newsletter::factory()->for($this->user)
            ->withFilters($this->filtersFor('lists', [$this->list->id], 'none'))
            ->create(['status' => 'draft', 'title' => 'Spring sale']);

        $this->assertDestroyIsBlockedBy('Spring sale');
    }

    public function test_destroy_is_blocked_while_a_scheduled_newsletter_filters_by_a_topic_of_the_list(): void
    {
        $topic = Topic::factory()->for($this->user)->create(['list_id' => $this->list->id]);
        Newsletter::factory()->for($this->user)
            ->withFilters($this->filtersFor('topics', [$topic->id]))
            ->create(['status' => 'scheduled', 'title' => 'Weekly digest']);

        $this->assertDestroyIsBlockedBy('Weekly digest');
    }

    public function test_destroy_is_blocked_while_a_sequence_filters_by_the_list(): void
    {
        Sequence::factory()->for($this->user)
            ->withFilters($this->filtersFor('lists', [$this->list->id]))
            ->create(['name' => 'Welcome']);

        $this->assertDestroyIsBlockedBy('Welcome');
    }

    public function test_destroy_is_blocked_while_a_sequence_step_filters_by_the_list(): void
    {
        $sequence = Sequence::factory()->for($this->user)->create(['name' => 'Onboarding']);
        SequenceStep::factory()->for($sequence)->create(['filters' => $this->filtersFor('lists', [(string) $this->list->id])]);

        $this->assertDestroyIsBlockedBy('Onboarding');
    }

    public function test_destroy_is_blocked_while_a_segment_filters_by_the_list(): void
    {
        Segment::factory()
            ->withFilters($this->filtersFor('lists', [$this->list->id]))
            ->create(['client_id' => $this->user->client_id, 'name' => 'Engaged buyers']);

        $this->assertDestroyIsBlockedBy('Engaged buyers');
    }

    public function test_destroy_is_blocked_while_an_automation_starts_from_the_list(): void
    {
        Automation::factory()->for($this->user)->paused()->create([
            'name' => 'Tag new members',
            'condition' => ['type' => 'contact_added_to_list', 'value' => $this->list->id],
            'actions' => [['type' => 'add_tag', 'value' => 999]],
        ]);

        $this->assertDestroyIsBlockedBy('Tag new members');
    }

    public function test_destroy_is_blocked_while_an_automation_adds_contacts_to_the_list(): void
    {
        Automation::factory()->for($this->user)->create([
            'name' => 'Move buyers',
            'condition' => ['type' => 'tag_added', 'value' => 999],
            'actions' => [['type' => 'add_list', 'value' => $this->list->id]],
        ]);

        $this->assertDestroyIsBlockedBy('Move buyers');
    }

    public function test_destroy_is_blocked_while_a_visual_automation_checks_the_list(): void
    {
        VisualAutomation::factory()->for($this->user)->create([
            'name' => 'Branch on list',
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'tag_added', 'value' => 999]],
                    ['id' => 'condition-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 100], 'data' => ['condition_type' => 'not_in_list', 'value' => $this->list->id]],
                ],
                'edges' => [],
            ],
        ]);

        $this->assertDestroyIsBlockedBy('Branch on list');
    }

    public function test_destroy_is_blocked_while_a_visual_automation_goal_uses_the_list(): void
    {
        VisualAutomation::factory()->for($this->user)->create([
            'name' => 'Goal on list',
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'tag_added', 'value' => 999]],
                ],
                'edges' => [],
                'goals' => [['type' => 'in_list', 'value' => $this->list->id]],
            ],
        ]);

        $this->assertDestroyIsBlockedBy('Goal on list');
    }

    public function test_destroy_is_allowed_when_only_sent_newsletters_and_other_lists_use_lists(): void
    {
        $otherList = MailList::factory()->for($this->user)->create();
        Newsletter::factory()->for($this->user)
            ->withFilters($this->filtersFor('lists', [$this->list->id]))
            ->create(['status' => 'sent']);
        Newsletter::factory()->for($this->user)
            ->withFilters($this->filtersFor('lists', [$otherList->id]))
            ->create(['status' => 'draft']);
        Automation::factory()->for($this->user)->create([
            'condition' => ['type' => 'contact_added_to_list', 'value' => $otherList->id],
            'actions' => [['type' => 'add_tag', 'value' => $this->list->id]],
        ]);
        VisualAutomation::factory()->for($this->user)->create([
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $otherList->id]],
                ],
                'edges' => [],
            ],
        ]);

        $this->destroy()->assertNoContent();

        $this->assertDatabaseMissing('lists', ['id' => $this->list->id]);
    }

    public function test_items_of_another_client_do_not_block_the_destroy(): void
    {
        Newsletter::factory()->for(User::factory()->create())
            ->withFilters($this->filtersFor('lists', [$this->list->id]))
            ->create(['status' => 'draft']);

        $this->destroy()->assertNoContent();
    }
}
