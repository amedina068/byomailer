<?php

namespace Tests\Feature;

use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisualAutomation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisualAutomationGoalValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailList $list;

    private Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->list = MailList::factory()->for($this->user)->create();
        $this->tag = Tag::factory()->for($this->user)->create();
    }

    /**
     * @param  array<int, mixed>|null  $goals
     * @param  array<int, mixed>|null  $exitConditions
     * @return array<string, mixed>
     */
    private function workflow(?array $goals = null, ?array $exitConditions = null): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $this->list->id]],
                ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $this->tag->id]],
            ],
            'edges' => [['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1']],
            'goals' => $goals,
            'exit_conditions' => $exitConditions,
        ];
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function store(array $workflow): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('visual-automations.create'))
            ->post(route('visual-automations.store'), ['name' => 'Nurture', 'workflow' => $workflow]);
    }

    public function test_the_store_saves_goals_and_exit_conditions(): void
    {
        $topic = Topic::factory()->for($this->user)->create(['list_id' => $this->list->id]);

        $this->store($this->workflow(
            goals: [['type' => 'has_tag', 'value' => $this->tag->id]],
            exitConditions: [['type' => 'subscribed_to_topic', 'value' => $topic->id]],
        ))->assertSessionHasNoErrors();

        $automation = VisualAutomation::query()->sole();
        $this->assertSame([['type' => 'has_tag', 'value' => $this->tag->id]], $automation->goals());
        $this->assertSame([['type' => 'subscribed_to_topic', 'value' => $topic->id]], $automation->exitConditions());
    }

    public function test_a_workflow_without_rules_is_valid(): void
    {
        $workflow = $this->workflow();
        unset($workflow['goals'], $workflow['exit_conditions']);

        $this->store($workflow)->assertSessionHasNoErrors();

        $this->assertFalse(VisualAutomation::query()->sole()->hasGoalsOrExitConditions());
    }

    public function test_the_update_replaces_the_rules(): void
    {
        $automation = VisualAutomation::factory()->for($this->user)->create([
            'workflow' => $this->workflow(goals: [['type' => 'has_tag', 'value' => $this->tag->id]]),
        ]);

        $this->actingAs($this->user)
            ->put(route('visual-automations.update', $automation), [
                'name' => 'Nurture',
                'workflow' => $this->workflow(goals: [], exitConditions: [['type' => 'in_list', 'value' => $this->list->id]]),
            ])
            ->assertSessionHasNoErrors();

        $automation->refresh();
        $this->assertSame([], $automation->goals());
        $this->assertSame([['type' => 'in_list', 'value' => $this->list->id]], $automation->exitConditions());
    }

    public function test_the_show_page_sends_the_rules(): void
    {
        $automation = VisualAutomation::factory()->for($this->user)->create([
            'workflow' => $this->workflow(goals: [['type' => 'has_tag', 'value' => $this->tag->id]]),
        ]);

        $this->actingAs($this->user)
            ->get(route('visual-automations.show', $automation))
            ->assertInertia(fn (Assert $page) => $page
                ->where('automation.workflow.goals.0.type', 'has_tag')
                ->where('automation.workflow.goals.0.value', $this->tag->id));
    }

    public function test_the_store_refuses_more_than_ten_goals(): void
    {
        $goals = array_fill(0, 11, ['type' => 'has_tag', 'value' => $this->tag->id]);

        $this->store($this->workflow(goals: $goals))
            ->assertSessionHasErrors(['workflow.goals' => 'The goals field must not have more than 10 items.']);

        $this->assertDatabaseCount('visual_automations', 0);
    }

    public function test_the_store_refuses_more_than_ten_exit_conditions(): void
    {
        $exitConditions = array_fill(0, 11, ['type' => 'has_tag', 'value' => $this->tag->id]);

        $this->store($this->workflow(exitConditions: $exitConditions))
            ->assertSessionHasErrors(['workflow.exit_conditions' => 'The exit conditions field must not have more than 10 items.']);
    }

    public function test_the_store_refuses_a_negative_rule_type(): void
    {
        $this->store($this->workflow(goals: [['type' => 'not_has_tag', 'value' => $this->tag->id]]))
            ->assertSessionHasErrors(['workflow.goals.0.type' => 'The selected goal type is invalid.']);
    }

    public function test_the_store_refuses_a_rule_without_a_value(): void
    {
        $this->store($this->workflow(exitConditions: [['type' => 'has_tag']]))
            ->assertSessionHasErrors(['workflow.exit_conditions.0.value' => 'The exit condition value field is required.']);
    }

    public function test_the_store_accepts_a_rule_on_a_tag_of_a_teammate(): void
    {
        $teammateTag = Tag::factory()->for(User::factory()->teammateOf($this->user)->create())->create();

        $this->store($this->workflow(goals: [['type' => 'has_tag', 'value' => $teammateTag->id]]))
            ->assertSessionHasNoErrors();
    }

    /**
     * @return array<string, array{string, class-string<Model>, string}>
     */
    public static function foreignResources(): array
    {
        return [
            'tag' => ['has_tag', Tag::class, 'tags'],
            'list' => ['in_list', MailList::class, 'lists'],
            'topic' => ['subscribed_to_topic', Topic::class, 'topics'],
            'sequence' => ['in_sequence', Sequence::class, 'sequences'],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('foreignResources')]
    public function test_the_store_refuses_a_goal_on_a_resource_of_another_client(string $type, string $model, string $category): void
    {
        $foreign = $model::factory()->for(User::factory()->create())->create();

        $this->store($this->workflow(goals: [['type' => $type, 'value' => $foreign->id]]))
            ->assertSessionHasErrors(['workflow.goals' => "One or more selected {$category} do not belong to your account."]);

        $this->assertDatabaseCount('visual_automations', 0);
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('foreignResources')]
    public function test_the_store_refuses_an_exit_condition_on_a_resource_of_another_client(string $type, string $model, string $category): void
    {
        $foreign = $model::factory()->for(User::factory()->create())->create();

        $this->store($this->workflow(exitConditions: [['type' => $type, 'value' => $foreign->id]]))
            ->assertSessionHasErrors(['workflow.exit_conditions' => "One or more selected {$category} do not belong to your account."]);
    }
}
