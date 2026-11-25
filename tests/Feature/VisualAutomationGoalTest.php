<?php

namespace Tests\Feature;

use App\Enums\EnrollmentExitReason;
use App\Jobs\ProcessVisualAutomationEnrollmentJob;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationEnrollment;
use App\Services\VisualAutomationEngine;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VisualAutomationGoalTest extends TestCase
{
    use RefreshDatabase;

    private VisualAutomationEngine $engine;

    private User $user;

    private Tag $nextTag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(VisualAutomationEngine::class);
        $this->user = User::factory()->create();
        $this->actingAsTenant($this->user->client_id);
        $this->nextTag = Tag::factory()->for($this->user)->create();
    }

    /**
     * A live automation that waits one day and then adds the next tag.
     *
     * @param  list<array{type: string, value: int}>  $goals
     * @param  list<array{type: string, value: int}>  $exitConditions
     */
    private function automationWithDelay(array $goals = [], array $exitConditions = []): VisualAutomation
    {
        return VisualAutomation::factory()->for($this->user)->live()->create([
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 'delay-1', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 280], 'data' => ['duration' => 1, 'unit' => 'days']],
                    ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $this->nextTag->id]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'delay-1'],
                    ['id' => 'e2', 'source' => 'delay-1', 'target' => 'action-1'],
                ],
                'goals' => $goals,
                'exit_conditions' => $exitConditions,
            ],
        ]);
    }

    /**
     * Enroll the contact and run the first tick, so the contact waits in the delay step.
     */
    private function parkInDelay(VisualAutomation $automation, Contact $contact): VisualAutomationEnrollment
    {
        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $this->assertSame('waiting', $enrollment->steps()->where('node_id', 'delay-1')->value('status'));

        return $enrollment;
    }

    private function assertEndedWith(VisualAutomationEnrollment $enrollment, ?EnrollmentExitReason $reason): void
    {
        $enrollment = $enrollment->fresh();

        $this->assertSame('completed', $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
        $this->assertSame($reason, $enrollment->exit_reason);
    }

    /**
     * @return list<int>
     */
    private function dispatchedEnrollmentIds(): array
    {
        Queue::fake();
        Context::forgetHidden(Tenant::CONTEXT_KEY);

        $this->artisan('visual-automations:process')->assertSuccessful();

        return Queue::pushed(ProcessVisualAutomationEnrollmentJob::class)
            ->map(fn (ProcessVisualAutomationEnrollmentJob $job): int => $job->enrollmentId)
            ->sort()
            ->values()
            ->all();
    }

    public function test_a_goal_ends_the_enrollment_of_a_contact_in_a_delay_step(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $enrollment = $this->parkInDelay($this->automationWithDelay(goals: [['type' => 'has_tag', 'value' => $tag->id]]), $contact);

        $contact->tags()->attach($tag);
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, EnrollmentExitReason::Goal);
        $this->assertSame('skipped', $enrollment->steps()->where('node_id', 'delay-1')->value('status'));

        $this->travel(2)->days();
        $this->engine->processAllPending();

        $this->assertFalse($contact->tags()->whereKey($this->nextTag->id)->exists());
        $this->assertFalse($enrollment->steps()->where('node_id', 'action-1')->exists());
    }

    public function test_an_exit_condition_ends_the_enrollment_of_a_contact_in_a_delay_step(): void
    {
        $list = MailList::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $enrollment = $this->parkInDelay($this->automationWithDelay(exitConditions: [['type' => 'in_list', 'value' => $list->id]]), $contact);

        $contact->lists()->attach($list);
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, EnrollmentExitReason::ExitCondition);
        $this->assertSame('skipped', $enrollment->steps()->where('node_id', 'delay-1')->value('status'));
    }

    public function test_a_goal_wins_when_the_contact_also_meets_an_exit_condition(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $list = MailList::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $contact->tags()->attach($tag);
        $contact->lists()->attach($list);

        $enrollment = $this->engine->enroll($this->automationWithDelay(
            goals: [['type' => 'has_tag', 'value' => $tag->id]],
            exitConditions: [['type' => 'in_list', 'value' => $list->id]],
        ), $contact);
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, EnrollmentExitReason::Goal);
        $this->assertSame('skipped', $enrollment->steps()->where('node_id', 'delay-1')->value('status'));
    }

    public function test_a_contact_that_meets_the_second_rule_leaves(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $otherTag = Tag::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $enrollment = $this->parkInDelay($this->automationWithDelay(goals: [
            ['type' => 'has_tag', 'value' => $otherTag->id],
            ['type' => 'has_tag', 'value' => $tag->id],
        ]), $contact);

        $contact->tags()->attach($tag);
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, EnrollmentExitReason::Goal);
    }

    public function test_a_goal_that_an_action_of_the_automation_reaches_stops_the_next_steps(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $list = MailList::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $automation = VisualAutomation::factory()->for($this->user)->live()->create([
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
                    ['id' => 'action-list', 'type' => 'action', 'position' => ['x' => 300, 'y' => 280], 'data' => ['action_type' => 'add_list', 'value' => $list->id]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-tag'],
                    ['id' => 'e2', 'source' => 'trigger-1', 'target' => 'action-list'],
                ],
                'goals' => [['type' => 'has_tag', 'value' => $tag->id]],
            ],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, EnrollmentExitReason::Goal);
        $this->assertTrue($contact->tags()->whereKey($tag->id)->exists());
        $this->assertFalse($contact->lists()->whereKey($list->id)->exists());
        $this->assertSame('skipped', $enrollment->steps()->where('node_id', 'action-list')->value('status'));
    }

    public function test_an_exit_condition_ends_the_enrollment_of_a_contact_that_waits_for_a_sequence(): void
    {
        $sequence = Sequence::factory()->for($this->user)->create(['status' => 'live']);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        $exitTag = Tag::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $automation = VisualAutomation::factory()->for($this->user)->live()->create([
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
                    ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $this->nextTag->id]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-seq'],
                    ['id' => 'e2', 'source' => 'action-seq', 'target' => 'action-tag'],
                ],
                'exit_conditions' => [['type' => 'has_tag', 'value' => $exitTag->id]],
            ],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();
        $this->assertSame('waiting', $enrollment->steps()->where('node_id', 'action-seq')->value('status'));

        $contact->tags()->attach($exitTag);
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, EnrollmentExitReason::ExitCondition);
        $this->assertSame('skipped', $enrollment->steps()->where('node_id', 'action-seq')->value('status'));

        $this->engine->advanceWaitingSequenceSteps($contact, $sequence);
        $this->engine->processAllPending();

        $this->assertFalse($enrollment->steps()->where('node_id', 'action-tag')->exists());
        $this->assertFalse($contact->tags()->whereKey($this->nextTag->id)->exists());
    }

    public function test_the_cron_dispatches_a_parked_enrollment_that_meets_a_rule(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $automation = $this->automationWithDelay(goals: [['type' => 'has_tag', 'value' => $tag->id]]);
        $met = $this->parkInDelay($automation, Contact::factory()->for($this->user)->create());
        $this->parkInDelay($automation, Contact::factory()->for($this->user)->create());

        $met->contact->tags()->attach($tag);

        $this->assertSame([$met->id], $this->dispatchedEnrollmentIds());
    }

    public function test_the_cron_dispatches_an_enrollment_once_when_it_is_ready_and_meets_a_rule(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $contact->tags()->attach($tag);
        $enrollment = $this->engine->enroll($this->automationWithDelay(goals: [['type' => 'has_tag', 'value' => $tag->id]]), $contact);

        $this->assertSame([$enrollment->id], $this->dispatchedEnrollmentIds());
    }

    public function test_the_cron_reads_each_rule_type(): void
    {
        $list = MailList::factory()->for($this->user)->create();
        $topic = Topic::factory()->for($this->user)->create(['list_id' => $list->id]);
        $sequence = Sequence::factory()->for($this->user)->create(['status' => 'live']);
        $otherList = MailList::factory()->for($this->user)->create();
        $automation = $this->automationWithDelay(exitConditions: [
            ['type' => 'in_list', 'value' => $otherList->id],
            ['type' => 'subscribed_to_topic', 'value' => $topic->id],
            ['type' => 'in_sequence', 'value' => $sequence->id],
        ]);

        $inList = $this->parkInDelay($automation, Contact::factory()->for($this->user)->create());
        $inTopic = $this->parkInDelay($automation, Contact::factory()->for($this->user)->create());
        $inSequence = $this->parkInDelay($automation, Contact::factory()->for($this->user)->create());
        $leftTopic = $this->parkInDelay($automation, Contact::factory()->for($this->user)->create());

        $inList->contact->lists()->attach($otherList);
        $inTopic->contact->topics()->attach($topic, ['subscribed_at' => now()]);
        $inSequence->contact->sequences()->attach($sequence);
        $leftTopic->contact->topics()->attach($topic, ['subscribed_at' => now(), 'unsubscribed_at' => now()]);

        $this->assertSame([$inList->id, $inTopic->id, $inSequence->id], $this->dispatchedEnrollmentIds());

        $this->engine->processAllPending();

        foreach ([$inList, $inTopic, $inSequence] as $enrollment) {
            $this->assertEndedWith($enrollment, EnrollmentExitReason::ExitCondition);
        }
        $this->assertSame('active', $leftTopic->fresh()->status);
    }

    public function test_the_cron_skips_parked_enrollments_of_automations_without_rules(): void
    {
        $this->parkInDelay($this->automationWithDelay(), Contact::factory()->for($this->user)->create());

        $this->assertSame([], $this->dispatchedEnrollmentIds());
    }

    public function test_the_cron_skips_paused_automations_with_rules(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $automation = $this->automationWithDelay(goals: [['type' => 'has_tag', 'value' => $tag->id]]);
        $enrollment = $this->parkInDelay($automation, Contact::factory()->for($this->user)->create());
        $enrollment->contact->tags()->attach($tag);
        $automation->update(['status' => 'draft']);

        $this->assertSame([], $this->dispatchedEnrollmentIds());
    }

    public function test_the_job_ends_the_enrollment_with_no_tenant(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $enrollment = $this->parkInDelay($this->automationWithDelay(goals: [['type' => 'has_tag', 'value' => $tag->id]]), $contact);
        $contact->tags()->attach($tag);

        Context::forgetHidden(Tenant::CONTEXT_KEY);
        (new ProcessVisualAutomationEnrollmentJob($enrollment->id))->handle($this->engine);

        $this->assertEndedWith($enrollment, EnrollmentExitReason::Goal);
    }

    public function test_a_rule_for_a_deleted_tag_does_not_end_the_enrollment(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $enrollment = $this->parkInDelay($this->automationWithDelay(goals: [['type' => 'has_tag', 'value' => $tag->id]]), $contact);

        $tag->delete();
        $this->engine->processAllPending();

        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->exit_reason);
    }

    public function test_an_unsubscribed_contact_leaves_with_the_unsubscribed_reason(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $enrollment = $this->parkInDelay($this->automationWithDelay(goals: [['type' => 'has_tag', 'value' => $tag->id]]), $contact);

        $contact->tags()->attach($tag);
        $contact->update(['unsubscribed_at' => now()]);
        $this->travel(2)->days();
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, EnrollmentExitReason::Unsubscribed);
    }

    public function test_the_unsubscribe_action_sets_the_unsubscribed_reason(): void
    {
        $contact = Contact::factory()->for($this->user)->create();
        $automation = VisualAutomation::factory()->for($this->user)->live()->create([
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'unsubscribe_contact']],
                ],
                'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1']],
            ],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, EnrollmentExitReason::Unsubscribed);
    }

    public function test_a_contact_that_reaches_the_end_leaves_with_no_reason(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        $enrollment = $this->parkInDelay($this->automationWithDelay(goals: [['type' => 'has_tag', 'value' => $tag->id]]), $contact);

        $this->travel(2)->days();
        $this->engine->processAllPending();
        $this->engine->processAllPending();

        $this->assertEndedWith($enrollment, null);
        $this->assertTrue($contact->tags()->whereKey($this->nextTag->id)->exists());
    }
}
