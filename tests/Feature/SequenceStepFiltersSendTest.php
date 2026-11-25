<?php

namespace Tests\Feature;

use App\Events\ContactSequenceFinished;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\User;
use App\Services\SequenceStepScheduler;
use App\Support\Tenant;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

class SequenceStepFiltersSendTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private User $user;

    private Sequence $sequence;

    private Tag $vipTag;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        $this->withoutSesSendingLimit();

        $this->user = User::factory()->create();
        EmailCredential::factory()->for($this->user)->create();
        $this->vipTag = Tag::factory()->for($this->user)->create();
        $this->sequence = Sequence::factory()->for($this->user)->create(['status' => 'live']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_contact_not_matching_step_filters_skips_only_that_step_and_gets_the_next_one(): void
    {
        $firstStep = $this->liveStep(0, $this->vipOnlyFilters());
        $secondStep = $this->liveStep(2);
        $contact = $this->enrolledContact();

        $this->expectNoMailSent();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $skippedMail = $this->mailFor($contact, $firstStep);
        $this->assertSame('skipped', $skippedMail->status);
        $this->assertNull($skippedMail->sent_at);

        $nextMail = $this->mailFor($contact, $secondStep);
        $this->assertNotNull($nextMail);
        $this->assertSame('pending', $nextMail->status);
        $this->assertSame('2026-01-17 10:00:00', $nextMail->scheduled_at->toDateTimeString());

        $this->assertTrue($contact->sequences()->withoutGlobalScopes()->where('sequences.id', $this->sequence->id)->exists());
    }

    public function test_skipping_the_last_step_completes_the_sequence(): void
    {
        Event::fake([ContactSequenceFinished::class]);

        $lastStep = $this->liveStep(0, $this->vipOnlyFilters());
        $contact = $this->enrolledContact();

        $this->expectNoMailSent();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame('skipped', $this->mailFor($contact, $lastStep)->status);
        $this->assertNotNull(
            $this->sequence->contacts()->withoutGlobalScopes()->whereKey($contact->id)->first()->pivot->completed_at
        );
        Event::assertDispatched(
            ContactSequenceFinished::class,
            fn (ContactSequenceFinished $event): bool => $event->contact->is($contact) && $event->sequence->is($this->sequence)
        );
    }

    public function test_skipping_the_last_step_fires_sequence_finished_only_after_the_skip_commits(): void
    {
        $lastStep = $this->liveStep(0, $this->vipOnlyFilters());
        $contact = $this->enrolledContact();
        $baseTransactionLevel = DB::transactionLevel();
        $transactionLevelsAtDispatch = [];

        Event::listen(ContactSequenceFinished::class, function () use (&$transactionLevelsAtDispatch): void {
            $transactionLevelsAtDispatch[] = DB::transactionLevel();
        });

        $this->expectNoMailSent();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame('skipped', $this->mailFor($contact, $lastStep)->status);
        $this->assertSame([$baseTransactionLevel], $transactionLevelsAtDispatch);
    }

    public function test_sequence_finished_is_not_fired_when_the_transaction_rolls_back(): void
    {
        $lastStep = $this->liveStep(0);
        $contact = $this->enrolledContact();
        $mail = $this->mailFor($contact, $lastStep);
        $dispatchCount = 0;

        Event::listen(ContactSequenceFinished::class, function () use (&$dispatchCount): void {
            $dispatchCount++;
        });

        try {
            DB::transaction(function () use ($mail, &$dispatchCount): void {
                app(SequenceStepScheduler::class)->scheduleNextStep($mail);

                $this->assertSame(0, $dispatchCount);

                throw new RuntimeException('Roll back the skip.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $dispatchCount);
        $this->assertNull($this->sequence->contacts()->withoutGlobalScopes()->whereKey($contact->id)->first()->pivot->completed_at);
    }

    public function test_contact_matching_step_filters_receives_the_step(): void
    {
        $step = $this->liveStep(0, $this->vipOnlyFilters());
        $contact = $this->enrolledContact();
        $contact->tags()->attach($this->vipTag);

        $this->expectMailsSent(1);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame('sent', $this->mailFor($contact, $step)->status);
    }

    public function test_step_without_filters_sends_as_before(): void
    {
        $this->liveStep(0, []);
        $this->enrolledContact();

        $this->expectMailsSent(1);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);
    }

    public function test_a_second_run_does_not_send_or_reschedule_the_skipped_step(): void
    {
        $firstStep = $this->liveStep(0, $this->vipOnlyFilters());
        $this->liveStep(2);
        $contact = $this->enrolledContact();

        $this->expectNoMailSent();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);
        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame(1, ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_id', $firstStep->id)
            ->count());
        $this->assertSame(2, ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)->count());
    }

    public function test_sequence_filter_mismatch_skips_the_mail_and_does_not_advance(): void
    {
        $this->sequence->update(['filters' => $this->vipOnlyFilters()]);

        $firstStep = $this->liveStep(0);
        $secondStep = $this->liveStep(2);
        $contact = Contact::factory()->for($this->user)->create();
        $contact->tags()->attach($this->vipTag);
        Tenant::run($this->user->client_id, fn () => $contact->syncSequences([$this->sequence->id], true));
        $contact->tags()->detach($this->vipTag);

        $this->expectNoMailSent();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame('skipped', $this->mailFor($contact, $firstStep)->status);
        $this->assertSame(SequenceStepScheduler::FILTER_EXIT_REASON, $this->mailFor($contact, $firstStep)->failure_reason);
        $this->assertNull($this->mailFor($contact, $secondStep));
        $this->assertNotNull(
            $this->sequence->contacts()->withoutGlobalScopes()->whereKey($contact->id)->first()->pivot->completed_at
        );
    }

    public function test_filter_on_a_deleted_tag_skips_the_step_without_error(): void
    {
        $firstStep = $this->liveStep(0, $this->vipOnlyFilters());
        $secondStep = $this->liveStep(2);
        $contact = $this->enrolledContact();
        $this->vipTag->delete();

        $this->expectNoMailSent();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame('skipped', $this->mailFor($contact, $firstStep)->status);
        $this->assertNotNull($this->mailFor($contact, $secondStep));
    }

    public function test_unsubscribed_contact_is_never_marked_skipped(): void
    {
        $this->liveStep(0, $this->vipOnlyFilters());
        $contact = $this->enrolledContact();
        Tenant::run($this->user->client_id, fn () => $contact->forceFill(['unsubscribed_at' => now()])->save());

        $this->expectNoMailSent();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame(0, ContactMail::withoutGlobalScopes()->where('status', 'skipped')->count());
    }

    public function test_skip_moves_past_a_draft_step_to_the_next_live_step(): void
    {
        $firstStep = $this->liveStep(0, $this->vipOnlyFilters());
        $draftStep = SequenceStep::factory()->for($this->sequence)->create([
            'delay_days' => 1,
            'delay_type' => 'days',
            'status' => 'draft',
        ]);
        $thirdStep = $this->liveStep(3);
        $contact = $this->enrolledContact();

        $this->expectNoMailSent();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame('skipped', $this->mailFor($contact, $firstStep)->status);
        $this->assertNull($this->mailFor($contact, $draftStep));
        $this->assertNotNull($this->mailFor($contact, $thirdStep));
    }

    public function test_a_failing_skip_marks_the_mail_failed_and_the_run_continues(): void
    {
        $firstStep = $this->liveStep(0, $this->vipOnlyFilters());
        $brokenNextStep = $this->liveStep(2);
        $brokenNextStep->message()->withoutGlobalScopes()->update(['email_identity_id' => null]);

        $skippingContact = $this->enrolledContact();
        $matchingContact = $this->enrolledContact();
        $matchingContact->tags()->attach($this->vipTag);

        $this->expectMailsSent(1);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $failedMail = $this->mailFor($skippingContact, $firstStep);
        $this->assertSame('failed', $failedMail->status);
        $this->assertNotNull($failedMail->failure_reason);
        $this->assertNull($this->mailFor($skippingContact, $brokenNextStep));
        $this->assertSame('sent', $this->mailFor($matchingContact, $firstStep)->status);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $filters
     */
    private function liveStep(int $delayDays, ?array $filters = null): SequenceStep
    {
        return SequenceStep::factory()->for($this->sequence)->create([
            'delay_days' => $delayDays,
            'delay_type' => 'days',
            'status' => 'live',
            'filters' => $filters,
        ]);
    }

    private function enrolledContact(): Contact
    {
        $contact = Contact::factory()->for($this->user)->create();
        Tenant::run($this->user->client_id, fn () => $contact->syncSequences([$this->sequence->id], true));

        return $contact;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vipOnlyFilters(): array
    {
        return [
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => [$this->vipTag->name],
            ],
        ];
    }

    private function mailFor(Contact $contact, SequenceStep $step): ?ContactMail
    {
        return ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->first();
    }

    private function expectNoMailSent(): void
    {
        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);
    }

    private function expectMailsSent(int $times): void
    {
        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->andReturn($mailer);
        $mailer->shouldReceive('send')->times($times)->andReturnNull();
        $this->app->instance(MailManager::class, $mailManager);
    }
}
