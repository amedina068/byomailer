<?php

namespace Tests\Feature;

use App\Events\ContactSequenceFinished;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use App\Services\SequenceStepScheduler;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * A contact who stops matching the sequence filters leaves the sequence.
 * The contact must finish the sequence, so the "sequence finished"
 * automations run, and the pending mail must stay as a `skipped` row.
 */
class SequenceFilterExitTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private User $user;

    private Sequence $sequence;

    private Tag $paidTag;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-10 08:00:00'));
        $this->withoutSesSendingLimit();

        $this->user = User::factory()->create();
        EmailCredential::factory()->for($this->user)->create();
        $this->paidTag = Tag::factory()->for($this->user)->create(['name' => 'PaidUser']);
        $this->sequence = Sequence::factory()->for($this->user)->create([
            'status' => 'live',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'tags',
                    'mode' => 'none',
                    'values' => ['PaidUser'],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_excluded_tag_completes_the_sequence_and_fires_the_event_once(): void
    {
        $this->actingAsTenant($this->user->client_id);

        Event::fake([ContactSequenceFinished::class]);

        $firstStep = $this->liveStep(1);
        $this->liveStep(3);
        $contact = $this->enrolledContact();

        $this->assertSame('pending', $this->mailFor($contact, $firstStep)->status);

        Carbon::setTestNow(Carbon::parse('2026-03-10 08:05:00'));
        $contact->syncTags([$this->paidTag->id], true);

        $this->assertSame('2026-03-10 08:05:00', $this->completedAt($contact));
        Event::assertDispatchedTimes(ContactSequenceFinished::class, 1);
        Event::assertDispatched(
            ContactSequenceFinished::class,
            fn (ContactSequenceFinished $event): bool => $event->contact->is($contact) && $event->sequence->is($this->sequence)
        );

        $otherTag = Tag::factory()->for($this->user)->create();
        Carbon::setTestNow(Carbon::parse('2026-03-10 09:00:00'));
        $contact->syncTags([$otherTag->id], true);

        $this->assertSame('2026-03-10 08:05:00', $this->completedAt($contact));
        Event::assertDispatchedTimes(ContactSequenceFinished::class, 1);
    }

    public function test_excluded_tag_keeps_the_pending_mail_as_skipped_and_never_sends_it(): void
    {
        $firstStep = $this->liveStep(1);
        $secondStep = $this->liveStep(3);
        [$contact, $mail] = Tenant::run($this->user->client_id, function () use ($firstStep): array {
            $contact = $this->enrolledContact();
            $contact->syncTags([$this->paidTag->id], true);

            return [$contact, $this->mailFor($contact, $firstStep)];
        });
        $this->assertSame('skipped', $mail->status);
        $this->assertSame(SequenceStepScheduler::FILTER_EXIT_REASON, $mail->failure_reason);

        $this->expectNoMailSent();
        Carbon::setTestNow(Carbon::parse('2026-03-20 08:00:00'));

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $mail->refresh();
        $this->assertSame('skipped', $mail->status);
        $this->assertNull($mail->sent_at);
        $this->assertNull(Tenant::run($this->user->client_id, fn (): ?ContactMail => $this->mailFor($contact, $secondStep)));
    }

    public function test_excluded_tag_runs_the_sequence_finished_automation(): void
    {
        $this->actingAsTenant($this->user->client_id);

        $laneTag = Tag::factory()->for($this->user)->create(['name' => 'lane_clicked']);
        Automation::factory()->for($this->user)->create([
            'type' => 'simple',
            'condition' => ['type' => 'sequence_finished', 'value' => $this->sequence->id],
            'actions' => [
                ['type' => 'remove_tag', 'value' => $laneTag->id],
            ],
        ]);

        $this->liveStep(1);
        $contact = $this->enrolledContact();
        $contact->tags()->attach($laneTag);

        $contact->syncTags([$this->paidTag->id], true);

        $this->assertFalse($contact->fresh()->tags()->whereKey($laneTag->id)->exists());
        $this->assertTrue($contact->fresh()->tags()->whereKey($this->paidTag->id)->exists());
    }

    public function test_filter_mismatch_after_a_delivered_step_completes_the_sequence_once(): void
    {
        $this->actingAsTenant($this->user->client_id);

        Event::fake([ContactSequenceFinished::class]);

        $firstStep = $this->liveStep(0);
        $secondStep = $this->liveStep(3);
        $contact = $this->enrolledContact();

        $delivered = $this->mailFor($contact, $firstStep);
        $delivered->forceFill(['status' => 'delivered', 'sent_at' => now(), 'delivered_at' => now()])->save();

        $contact->tags()->attach($this->paidTag);

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleNextStep($delivered);
        $scheduler->scheduleNextStep($delivered);

        $this->assertNull($this->mailFor($contact, $secondStep));
        $this->assertSame('2026-03-10 08:00:00', $this->completedAt($contact));
        Event::assertDispatchedTimes(ContactSequenceFinished::class, 1);
    }

    public function test_filter_mismatch_before_the_first_step_is_scheduled_completes_the_sequence(): void
    {
        $this->actingAsTenant($this->user->client_id);

        Event::fake([ContactSequenceFinished::class]);

        $firstStep = $this->liveStep(1);
        $contact = Contact::factory()->for($this->user)->create();
        $contact->sequences()->attach($this->sequence->id);
        $contact->tags()->attach($this->paidTag);

        app(SequenceStepScheduler::class)->scheduleInitialSteps($contact, [$this->sequence->id]);

        $this->assertNull($this->mailFor($contact, $firstStep));
        $this->assertSame('2026-03-10 08:00:00', $this->completedAt($contact));
        Event::assertDispatchedTimes(ContactSequenceFinished::class, 1);
    }

    public function test_contact_who_still_matches_gets_the_next_step_and_does_not_complete(): void
    {
        $this->actingAsTenant($this->user->client_id);

        Event::fake([ContactSequenceFinished::class]);

        $firstStep = $this->liveStep(0);
        $secondStep = $this->liveStep(3);
        $contact = $this->enrolledContact();

        $delivered = $this->mailFor($contact, $firstStep);
        $delivered->forceFill(['status' => 'delivered', 'sent_at' => now(), 'delivered_at' => now()])->save();

        app(SequenceStepScheduler::class)->scheduleNextStep($delivered);

        $this->assertSame('pending', $this->mailFor($contact, $secondStep)->status);
        $this->assertNull($this->completedAt($contact));
        Event::assertNotDispatched(ContactSequenceFinished::class);
    }

    public function test_normal_finish_completes_the_sequence_and_fires_the_event_once(): void
    {
        $this->actingAsTenant($this->user->client_id);

        Event::fake([ContactSequenceFinished::class]);

        $onlyStep = $this->liveStep(0);
        $contact = $this->enrolledContact();

        $delivered = $this->mailFor($contact, $onlyStep);
        $delivered->forceFill(['status' => 'delivered', 'sent_at' => now(), 'delivered_at' => now()])->save();

        $scheduler = app(SequenceStepScheduler::class);
        $scheduler->scheduleNextStep($delivered);
        $scheduler->scheduleNextStep($delivered);

        $this->assertSame('delivered', $delivered->fresh()->status);
        $this->assertSame('2026-03-10 08:00:00', $this->completedAt($contact));
        Event::assertDispatchedTimes(ContactSequenceFinished::class, 1);
    }

    public function test_deleting_a_step_does_not_reschedule_a_contact_who_left_by_filter(): void
    {
        $this->actingAsTenant($this->user->client_id);

        $firstStep = $this->liveStep(1);
        $secondStep = $this->liveStep(3);
        $contact = $this->enrolledContact();

        $contact->syncTags([$this->paidTag->id], true);
        $contact->tags()->detach($this->paidTag);

        $this->actingAs($this->user)
            ->delete(route('sequences.steps.destroy', [$this->sequence->id, $firstStep->id]))
            ->assertRedirect();

        $this->assertNull($this->mailFor($contact, $secondStep));
    }

    private function liveStep(int $delayDays): SequenceStep
    {
        return SequenceStep::factory()->for($this->sequence)->create([
            'delay_days' => $delayDays,
            'delay_type' => 'days',
            'status' => 'live',
        ]);
    }

    private function enrolledContact(): Contact
    {
        $contact = Contact::factory()->for($this->user)->create();
        $contact->syncSequences([$this->sequence->id], true);

        return $contact;
    }

    private function mailFor(Contact $contact, SequenceStep $step): ?ContactMail
    {
        return ContactMail::query()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->first();
    }

    private function completedAt(Contact $contact): ?string
    {
        return $this->sequence->contacts()->whereKey($contact->id)->first()->pivot->completed_at;
    }

    private function expectNoMailSent(): void
    {
        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);
    }
}
