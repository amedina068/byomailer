<?php

namespace Tests\Feature;

use App\Jobs\ProcessVisualAutomationEnrollmentJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationEnrollment;
use App\Services\VisualAutomationEngine;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisualAutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    private VisualAutomationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(VisualAutomationEngine::class);
    }

    private function createAutomationWithWorkflow(User $user, array $nodes, array $edges): VisualAutomation
    {
        return VisualAutomation::factory()->for($user)->live()->create([
            'workflow' => ['nodes' => $nodes, 'edges' => $edges],
        ]);
    }

    public function test_enroll_creates_enrollment_and_first_step(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => 1]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);

        $this->assertNotNull($enrollment);
        $this->assertSame('active', $enrollment->status);
        $this->assertSame($contact->id, $enrollment->contact_id);
        $this->assertCount(1, $enrollment->steps);
        $this->assertSame('action-1', $enrollment->steps->first()->node_id);
        $this->assertSame('pending', $enrollment->steps->first()->status);
    }

    public function test_enroll_prevents_duplicate_enrollment(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
        ], []);

        $this->engine->enroll($automation, $contact);
        $second = $this->engine->enroll($automation, $contact);

        $this->assertNull($second);
        $this->assertSame(1, $automation->enrollments()->count());
    }

    public function test_process_action_node_adds_tag(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());

        $enrollment->refresh();
        $this->assertSame('completed', $enrollment->status);
    }

    public function test_process_action_node_removes_tag(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->tags()->attach($tag);

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'remove_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
        ]);

        $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $contact->refresh();
        $this->assertFalse($contact->tags()->where('tags.id', $tag->id)->exists());
    }

    public function test_process_action_node_adds_to_list(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_list', 'value' => $list->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
        ]);

        $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $contact->refresh();
        $this->assertTrue($contact->lists()->where('lists.id', $list->id)->exists());
    }

    public function test_process_delay_node_sets_wait_until(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'delay-1', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 280], 'data' => ['duration' => 3, 'unit' => 'days']],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => 1]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'delay-1'],
            ['id' => 'e2', 'source' => 'delay-1', 'target' => 'action-1'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $step = $enrollment->steps()->where('node_id', 'delay-1')->first();
        $this->assertSame('waiting', $step->status);
        $this->assertNotNull($step->wait_until);
        $this->assertTrue($step->wait_until->isAfter(now()->addDays(2)));

        // The action step should not exist yet
        $this->assertFalse($enrollment->steps()->where('node_id', 'action-1')->exists());
    }

    public function test_delay_step_resumes_after_wait_time(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'delay-1', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 280], 'data' => ['duration' => 1, 'unit' => 'hours']],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'delay-1'],
            ['id' => 'e2', 'source' => 'delay-1', 'target' => 'action-1'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        // Should still be waiting
        $this->assertSame('active', $enrollment->fresh()->status);

        // Travel past the delay
        Carbon::setTestNow(now()->addHours(2));

        $this->engine->processAllPending();

        // The delay step should now be completed and action processed
        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());

        $enrollment->refresh();
        $this->assertSame('completed', $enrollment->status);

        Carbon::setTestNow();
    }

    public function test_condition_node_follows_yes_branch(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $yesList = MailList::factory()->for($user)->create();
        $noList = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->tags()->attach($tag);

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'condition-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 280], 'data' => ['condition_type' => 'has_tag', 'value' => $tag->id]],
            ['id' => 'action-yes', 'type' => 'action', 'position' => ['x' => -200, 'y' => 560], 'data' => ['action_type' => 'add_list', 'value' => $yesList->id]],
            ['id' => 'action-no', 'type' => 'action', 'position' => ['x' => 200, 'y' => 560], 'data' => ['action_type' => 'add_list', 'value' => $noList->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'condition-1'],
            ['id' => 'e2', 'source' => 'condition-1', 'target' => 'action-yes', 'sourceHandle' => 'yes'],
            ['id' => 'e3', 'source' => 'condition-1', 'target' => 'action-no', 'sourceHandle' => 'no'],
        ]);

        $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending(); // condition
        $this->engine->processAllPending(); // action

        $contact->refresh();
        $this->assertTrue($contact->lists()->where('lists.id', $yesList->id)->exists());
        $this->assertFalse($contact->lists()->where('lists.id', $noList->id)->exists());
    }

    public function test_condition_node_follows_no_branch(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $yesList = MailList::factory()->for($user)->create();
        $noList = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        // Contact does NOT have the tag

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'condition-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 280], 'data' => ['condition_type' => 'has_tag', 'value' => $tag->id]],
            ['id' => 'action-yes', 'type' => 'action', 'position' => ['x' => -200, 'y' => 560], 'data' => ['action_type' => 'add_list', 'value' => $yesList->id]],
            ['id' => 'action-no', 'type' => 'action', 'position' => ['x' => 200, 'y' => 560], 'data' => ['action_type' => 'add_list', 'value' => $noList->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'condition-1'],
            ['id' => 'e2', 'source' => 'condition-1', 'target' => 'action-yes', 'sourceHandle' => 'yes'],
            ['id' => 'e3', 'source' => 'condition-1', 'target' => 'action-no', 'sourceHandle' => 'no'],
        ]);

        $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending(); // condition
        $this->engine->processAllPending(); // action

        $contact->refresh();
        $this->assertFalse($contact->lists()->where('lists.id', $yesList->id)->exists());
        $this->assertTrue($contact->lists()->where('lists.id', $noList->id)->exists());
    }

    public function test_nested_conditions_work_correctly(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tagA = Tag::factory()->for($user)->create();
        $tagB = Tag::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);

        $contact = Contact::factory()->for($user)->create();
        $contact->tags()->attach($tagA);
        // Contact does NOT have tagB

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'cond-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 280], 'data' => ['condition_type' => 'has_tag', 'value' => $tagA->id]],
            ['id' => 'cond-2', 'type' => 'condition', 'position' => ['x' => -200, 'y' => 560], 'data' => ['condition_type' => 'has_tag', 'value' => $tagB->id]],
            ['id' => 'action-list', 'type' => 'action', 'position' => ['x' => -400, 'y' => 840], 'data' => ['action_type' => 'add_list', 'value' => $list->id]],
            ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 840], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'cond-1'],
            ['id' => 'e2', 'source' => 'cond-1', 'target' => 'cond-2', 'sourceHandle' => 'yes'],
            ['id' => 'e3', 'source' => 'cond-2', 'target' => 'action-list', 'sourceHandle' => 'yes'],
            ['id' => 'e4', 'source' => 'cond-2', 'target' => 'action-seq', 'sourceHandle' => 'no'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);

        // Process first condition (has tagA? YES → go to cond-2)
        $this->engine->processAllPending();
        // Process second condition (has tagB? NO → go to action-seq)
        $this->engine->processAllPending();
        // Process action
        $this->engine->processAllPending();

        $contact->refresh();
        $this->assertFalse($contact->lists()->where('lists.id', $list->id)->exists());
        $this->assertTrue($contact->sequences()->whereKey($sequence->id)->exists());

        // add_sequence now waits for the sequence to finish
        $enrollment->refresh();
        $this->assertSame('active', $enrollment->status);

        $step = $enrollment->steps()->where('node_id', 'action-seq')->first();
        $this->assertSame('waiting', $step->status);
    }

    public function test_multi_step_linear_workflow(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
            ['id' => 'action-2', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_list', 'value' => $list->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
            ['id' => 'e2', 'source' => 'action-1', 'target' => 'action-2'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending(); // action-1
        $this->engine->processAllPending(); // action-2

        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());
        $this->assertTrue($contact->lists()->where('lists.id', $list->id)->exists());
        $this->assertSame('completed', $enrollment->fresh()->status);
    }

    public function test_artisan_command_processes_steps(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
        ]);

        Tenant::run($user->client_id, fn () => $this->engine->enroll($automation, $contact));

        $this->artisan('visual-automations:process')
            ->assertSuccessful();

        $contact->refresh();
        $this->assertTrue(Tenant::run($user->client_id, fn (): bool => $contact->tags()->where('tags.id', $tag->id)->exists()));
    }

    public function test_in_list_condition_evaluates_correctly(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'cond-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 280], 'data' => ['condition_type' => 'in_list', 'value' => $list->id]],
            ['id' => 'action-yes', 'type' => 'action', 'position' => ['x' => -200, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'cond-1'],
            ['id' => 'e2', 'source' => 'cond-1', 'target' => 'action-yes', 'sourceHandle' => 'yes'],
        ]);

        $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending(); // condition
        $this->engine->processAllPending(); // action

        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());
    }

    public function test_add_sequence_action_waits_for_sequence_to_finish(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
            ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-seq'],
            ['id' => 'e2', 'source' => 'action-seq', 'target' => 'action-tag'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        // Contact should be in the sequence
        $this->assertTrue($contact->sequences()->whereKey($sequence->id)->exists());

        // Step should be waiting, not completed
        $step = $enrollment->steps()->where('node_id', 'action-seq')->first();
        $this->assertSame('waiting', $step->status);

        // Next action should NOT have been created
        $this->assertFalse($enrollment->steps()->where('node_id', 'action-tag')->exists());

        // Enrollment should still be active
        $this->assertSame('active', $enrollment->fresh()->status);
    }

    public function test_sequence_finished_event_advances_waiting_automation_step(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
            ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-seq'],
            ['id' => 'e2', 'source' => 'action-seq', 'target' => 'action-tag'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        // Now simulate sequence finishing
        $this->engine->advanceWaitingSequenceSteps($contact, $sequence);

        // The sequence step should now be completed
        $step = $enrollment->steps()->where('node_id', 'action-seq')->first();
        $this->assertSame('completed', $step->fresh()->status);

        // The next action step should now be pending
        $this->assertTrue($enrollment->steps()->where('node_id', 'action-tag')->where('status', 'pending')->exists());

        // Process the tag action
        $this->engine->processAllPending();

        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());
        $this->assertSame('completed', $enrollment->fresh()->status);
    }

    public function test_add_sequence_completes_immediately_if_sequence_already_finished(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        // Contact already in sequence and has the last step delivered
        $contact->sequences()->attach($sequence->id);
        ContactMail::create([
            'uuid' => (string) Str::uuid(),
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'subject' => 'Test',
            'from_email' => 'test@test.com',
            'from_name' => 'Test',
            'status' => 'delivered',
            'delivered_at' => now(),
            'scheduled_at' => now(),
        ]);

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
            ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-seq'],
            ['id' => 'e2', 'source' => 'action-seq', 'target' => 'action-tag'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending(); // should complete add_sequence immediately
        $this->engine->processAllPending(); // should process add_tag

        // Should have advanced past the sequence step
        $seqStep = $enrollment->steps()->where('node_id', 'action-seq')->first();
        $this->assertSame('completed', $seqStep->status);

        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());
        $this->assertSame('completed', $enrollment->fresh()->status);
    }

    public function test_add_sequence_completes_when_the_last_step_was_skipped(): void
    {
        $enrollment = $this->enrollWithLastSequenceStepMail('skipped');

        $this->assertSame('completed', $enrollment->steps()->where('node_id', 'action-seq')->first()->status);
    }

    public function test_add_sequence_keeps_waiting_when_the_last_step_is_sent_but_not_delivered(): void
    {
        $enrollment = $this->enrollWithLastSequenceStepMail('sent');

        $this->assertSame('waiting', $enrollment->steps()->where('node_id', 'action-seq')->first()->status);
    }

    private function enrollWithLastSequenceStepMail(string $status): VisualAutomationEnrollment
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $contact->sequences()->attach($sequence->id);
        ContactMail::create([
            'uuid' => (string) Str::uuid(),
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'subject' => 'Test',
            'from_email' => 'test@test.com',
            'from_name' => 'Test',
            'status' => $status,
            'scheduled_at' => now(),
        ]);

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
            ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-seq'],
            ['id' => 'e2', 'source' => 'action-seq', 'target' => 'action-tag'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        return $enrollment;
    }

    public function test_add_sequence_advances_immediately_when_filters_reject_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $requiredTag = Tag::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create([
            'status' => 'live',
            'filters' => [['category' => 'tags', 'values' => [$requiredTag->name], 'mode' => 'any']],
        ]);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        $resultTag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        // Contact does NOT have the required tag — filters will reject

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
            ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $resultTag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-seq'],
            ['id' => 'e2', 'source' => 'action-seq', 'target' => 'action-tag'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending(); // add_sequence — filters reject, should complete immediately
        $this->engine->processAllPending(); // add_tag

        // Contact should NOT be in the sequence
        $this->assertFalse($contact->sequences()->whereKey($sequence->id)->exists());

        // But automation should have advanced past it
        $seqStep = $enrollment->steps()->where('node_id', 'action-seq')->first();
        $this->assertSame('completed', $seqStep->status);

        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $resultTag->id)->exists());
        $this->assertSame('completed', $enrollment->fresh()->status);
    }

    public function test_catch_up_sweep_advances_stale_waiting_sequence_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
            ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-seq'],
            ['id' => 'e2', 'source' => 'action-seq', 'target' => 'action-tag'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        // Step should be waiting
        $step = $enrollment->steps()->where('node_id', 'action-seq')->first();
        $this->assertSame('waiting', $step->status);

        // Simulate the contact being removed from the sequence (e.g., unsubscribed and re-subscribed,
        // or filter enforcement detached them) — the event was missed
        $contact->sequences()->detach($sequence->id);

        // Run processAllPending — catch-up sweep should detect the contact is no longer in the sequence
        $this->engine->processAllPending();

        $step->refresh();
        $this->assertSame('completed', $step->status);

        // Next step should have been created and processed
        $this->engine->processAllPending();
        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());
        $this->assertSame('completed', $enrollment->fresh()->status);
    }

    public function test_node_step_counts_shown_on_show_page(): void
    {
        $user = User::factory()->create();
        $contact1 = Contact::factory()->for($user)->create();
        $contact2 = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'delay-1', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 280], 'data' => ['duration' => 3, 'unit' => 'days']],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => 1]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'delay-1'],
            ['id' => 'e2', 'source' => 'delay-1', 'target' => 'action-1'],
        ]);

        // Enroll both contacts and process — both should be waiting at delay-1
        $this->engine->enroll($automation, $contact1);
        $this->engine->enroll($automation, $contact2);
        $this->engine->processAllPending();

        $response = $this->actingAs($user)->get(route('visual-automations.show', $automation));

        $response->assertOk();
        $nodeStepCounts = $response->original->getData()['page']['props']['nodeStepCounts'];
        $this->assertEquals(2, $nodeStepCounts['delay-1']);
    }

    public function test_unsubscribe_action_halts_automation(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-unsub', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'unsubscribe_contact']],
            ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-unsub'],
            ['id' => 'e2', 'source' => 'action-unsub', 'target' => 'action-tag'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);

        // Unsubscribe step completed, but remaining steps skipped
        $unsubStep = $enrollment->steps()->where('node_id', 'action-unsub')->first();
        $this->assertSame('completed', $unsubStep->status);

        // Tag action should NOT have been created or executed
        $this->assertFalse($contact->tags()->where('tags.id', $tag->id)->exists());

        $enrollment->refresh();
        $this->assertSame('completed', $enrollment->status);
    }

    public function test_externally_unsubscribed_contact_skips_remaining_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
        ]);

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        $contact->refresh();
        $this->assertFalse($contact->tags()->where('tags.id', $tag->id)->exists());

        $step = $enrollment->steps()->where('node_id', 'action-1')->first();
        $this->assertSame('skipped', $step->status);

        $enrollment->refresh();
        $this->assertSame('completed', $enrollment->status);
    }

    public function test_job_processes_single_enrollment(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
        ]);

        $enrollment = Tenant::run($user->client_id, fn () => $this->engine->enroll($automation, $contact));

        (new ProcessVisualAutomationEnrollmentJob($enrollment->id))->handle($this->engine);

        $contact->refresh();
        $this->assertTrue(Tenant::run($user->client_id, fn (): bool => $contact->tags()->where('tags.id', $tag->id)->exists()));
        $this->assertSame('completed', $enrollment->fresh()->status);
    }

    public function test_paused_automation_enrollments_are_not_processed(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        // Create a draft automation (not live)
        $automation = VisualAutomation::factory()->for($user)->create([
            'status' => 'draft',
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
                ],
            ],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $processed = $this->engine->processAllPending();

        $this->assertSame(0, $processed);
        $step = $enrollment->steps()->where('node_id', 'action-1')->first();
        $this->assertSame('pending', $step->status);
        $this->assertFalse($contact->tags()->where('tags.id', $tag->id)->exists());

        // Now set to live and process
        $automation->update(['status' => 'live']);
        $processed = $this->engine->processAllPending();

        $this->assertSame(1, $processed);
        $contact->refresh();
        $this->assertTrue($contact->tags()->where('tags.id', $tag->id)->exists());
        $this->assertSame('completed', $enrollment->fresh()->status);
    }

    public function test_advance_waiting_sequence_steps_skips_paused_automation(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        SequenceStep::factory()->for($sequence)->create(['status' => 'live', 'delay_days' => 0]);
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-seq', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_sequence', 'value' => $sequence->id]],
            ['id' => 'action-tag', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-seq'],
            ['id' => 'e2', 'source' => 'action-seq', 'target' => 'action-tag'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);
        $this->engine->processAllPending();

        // Step should be waiting
        $step = $enrollment->steps()->where('node_id', 'action-seq')->first();
        $this->assertSame('waiting', $step->status);

        // Pause the automation
        $automation->update(['status' => 'draft']);

        // Try to advance — should be skipped because automation is paused
        $this->engine->advanceWaitingSequenceSteps($contact, $sequence);

        $step->refresh();
        $this->assertSame('waiting', $step->status);
        $this->assertFalse($enrollment->steps()->where('node_id', 'action-tag')->exists());
    }

    public function test_duplicate_step_creation_is_handled_gracefully(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $automation = $this->createAutomationWithWorkflow($user, [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
            ['id' => 'action-2', 'type' => 'action', 'position' => ['x' => 0, 'y' => 560], 'data' => ['action_type' => 'add_tag', 'value' => $tag->id]],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
            ['id' => 'e2', 'source' => 'action-1', 'target' => 'action-2'],
        ]);

        $enrollment = $this->engine->enroll($automation, $contact);

        // Manually create the step that the engine would create after action-1
        $enrollment->steps()->create([
            'node_id' => 'action-2',
            'status' => 'pending',
        ]);

        // Process action-1 — engine will try to create action-2 step again
        // Should not throw an exception due to unique constraint handling
        $this->engine->processAllPending();

        // Should still only have one step for action-2
        $this->assertSame(1, $enrollment->steps()->where('node_id', 'action-2')->count());
    }
}
