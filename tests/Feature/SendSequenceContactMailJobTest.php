<?php

namespace Tests\Feature;

use App\Enums\SequenceStatus;
use App\Events\SequenceStepSent;
use App\Jobs\ProcessSesEventJob;
use App\Jobs\SendSequenceContactMailJob;
use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Support\Tenant;
use Aws\Command;
use Aws\Exception\AwsException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\BuildsSesNotifications;
use Tests\Concerns\WorksTheSesSendingQueue;
use Tests\TestCase;
use Throwable;

class SendSequenceContactMailJobTest extends TestCase
{
    use BuildsSesNotifications;
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;
    use WorksTheSesSendingQueue;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sends_due_sequence_step_contact_mails_with_user_credentials_and_template_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLE123456789',
            'secret_key' => 'example-secret-key-1234567890',
            'region' => 'ap-south-1',
        ]);

        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => [
                'first_name' => 'Taylor',
            ],
        ]);

        $contactMail = $this->reservedSequenceMail($user, $contact, [], [
            'html_content' => '<p>Hello {{ first_name }}</p><p>{{ unsubscribe_url }}</p>',
            'text_content' => 'Plain {{ first_name }} copy',
        ]);
        $expectedTemplateData = $contactMail->sequenceStep->message->templateData($contact, $contactMail);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')
            ->once()
            ->withArgs(function (array $config) use ($user): bool {
                return ($config['name'] ?? null) === 'ses-user-'.$user->id
                    && ($config['transport'] ?? null) === 'ses'
                    && ($config['key'] ?? null) === 'AKIAEXAMPLE123456789'
                    && ($config['secret'] ?? null) === 'example-secret-key-1234567890'
                    && ($config['region'] ?? null) === 'ap-south-1';
            })
            ->andReturn($mailer);

        $mailer->shouldReceive('send')
            ->once()
            ->withArgs(fn (NewsletterMail $mail): bool => $mail->contactMail->is($contactMail))
            ->andReturnNull();

        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $contactMail);

        $contactMail->refresh();
        $this->assertSame($expectedTemplateData, $contactMail->template_data);
        $this->assertSame('sent', $contactMail->status);
        $this->assertSame('2026-01-15 10:00:00', $contactMail->sent_at->toDateTimeString());
        $this->assertNull($contactMail->reserved_at);
    }

    public function test_it_sends_due_hours_based_sequence_step_contact_mails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLE123456789',
            'secret_key' => 'example-secret-key-1234567890',
            'region' => 'ap-south-1',
        ]);

        $contact = Contact::factory()->for($user)->create();

        $contactMail = $this->reservedSequenceMail($user, $contact, [
            'delay_days' => 2,
            'delay_type' => 'hours',
        ], [
            'html_content' => '<p>Quick follow-up</p>',
            'text_content' => 'Quick follow-up',
        ]);

        $this->assertSame('2026-01-15 12:00:00', $contactMail->scheduled_at->toDateTimeString());

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:01:00'));

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')
            ->once()
            ->andReturn($mailer);

        $mailer->shouldReceive('send')
            ->once()
            ->andReturnNull();

        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $contactMail);

        $this->assertSame('sent', $contactMail->refresh()->status);
    }

    public function test_it_marks_contact_mail_as_failed_when_send_throws_exception(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);

        $this->mockMailerThrowing(new RuntimeException('SES rejected the request: Access denied'));

        $this->runJob($user, $contactMail);

        $contactMail->refresh();
        $this->assertSame('failed', $contactMail->status);
        $this->assertSame('SES rejected the request: Access denied', $contactMail->failure_reason);
        $this->assertNull($contactMail->sent_at);
    }

    public function test_job_marks_a_sunset_contact_mail_failed_without_sending(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $contact->forceFill(['sunset_at' => now()])->save();

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $contactMail);

        $contactMail->refresh();
        $this->assertSame('failed', $contactMail->status);
        $this->assertSame('sunset', $contactMail->failure_reason);
        $this->assertNull($contactMail->sent_at);
    }

    public function test_job_marks_an_unsubscribed_contact_mail_failed_without_sending(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        // A bulk update skips the observer that deletes pending mails, like
        // an unsubscribe that lands after the cron claimed the row.
        Contact::query()->whereKey($contact->id)->update(['unsubscribed_at' => now()]);

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $contactMail);

        $contactMail->refresh();
        $this->assertSame('failed', $contactMail->status);
        $this->assertSame('unsubscribed', $contactMail->failure_reason);
        $this->assertNull($contactMail->sent_at);
    }

    public function test_job_releases_a_mail_of_a_sequence_that_was_paused_after_the_claim(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $contactMail->sequenceStep->sequence->update(['status' => SequenceStatus::Draft]);

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $contactMail);

        $contactMail->refresh();
        $this->assertSame('pending', $contactMail->status);
        $this->assertNull($contactMail->reserved_at);
        $this->assertNull($contactMail->sent_at);
    }

    public function test_job_releases_a_mail_of_a_step_that_was_paused_after_the_claim(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $contactMail->sequenceStep->update(['status' => SequenceStatus::Draft]);

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $contactMail);

        $this->assertModelExists($contactMail);
        $this->assertSame('pending', $contactMail->fresh()->status);
    }

    public function test_job_deletes_a_mail_of_a_contact_that_left_the_sequence(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $contact->sequences()->detach();

        $this->runJob($user, $contactMail);

        $this->assertModelMissing($contactMail);
    }

    public function test_throttle_leaves_the_mail_pending_and_unreserved(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);

        $this->mockMailerThrowing(new AwsException('Throttled', new Command('SendEmail'), ['code' => 'Throttling']));

        $this->runJob($user, $contactMail);

        $contactMail->refresh();
        $this->assertSame('pending', $contactMail->status);
        $this->assertNull($contactMail->reserved_at);
        $this->assertNull($contactMail->failure_reason);
        $this->assertNull($contactMail->sent_at);
    }

    public function test_max_send_rate_message_from_the_mailer_counts_as_throttle(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);

        $this->mockMailerThrowing(new TransportException('Request to AWS SES API failed. Reason: Maximum sending rate exceeded.'));

        $this->runJob($user, $contactMail);

        $contactMail->refresh();
        $this->assertSame('pending', $contactMail->status);
        $this->assertNull($contactMail->reserved_at);
        $this->assertNull($contactMail->failure_reason);
    }

    public function test_job_leaves_a_mismatched_client_mail_untouched(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $otherUser = User::factory()->create();
        $otherContact = Contact::factory()->for($otherUser)->create();
        $otherMail = Tenant::run($otherUser->client_id, fn (): ContactMail => $this->reservedSequenceMail($otherUser, $otherContact));
        $reservedAt = $otherMail->reserved_at->toDateTimeString();

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $otherMail);

        $otherMail->refresh();
        $this->assertSame('pending', $otherMail->status);
        $this->assertNull($otherMail->sent_at);
        $this->assertSame($reservedAt, $otherMail->reserved_at->toDateTimeString());
    }

    public function test_job_skips_a_mail_that_is_not_reserved(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $contactMail->forceFill(['reserved_at' => null])->save();

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $contactMail);

        $this->assertSame('pending', $contactMail->refresh()->status);
    }

    public function test_reclaimed_rows_are_not_sent_by_the_older_job(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $olderToken = $contactMail->reserved_at;

        $contactMail->forceFill(['reserved_at' => now()->addMinutes(16)])->save();

        $mailManager = Mockery::mock(MailManager::class);
        $mailManager->shouldNotReceive('build');
        $this->app->instance(MailManager::class, $mailManager);

        $this->runJob($user, $contactMail, $olderToken);

        $this->assertSame('pending', $contactMail->refresh()->status);
        $this->assertSame('2026-01-15 10:16:00', $contactMail->reserved_at->toDateTimeString());
    }

    public function test_it_sends_from_the_sequence_author_name_when_the_identity_has_no_name(): void
    {
        $user = User::factory()->create(['name' => 'Ada Author']);
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $contactMail->sequenceStep->message->emailIdentity->update(['name' => null]);
        $this->mockMailerSending();

        $this->runJob($user, $contactMail);

        $contactMail->refresh();
        $this->assertSame('sent', $contactMail->status);
        $this->assertSame('Ada Author', $contactMail->from_name);
    }

    public function test_released_job_still_sends_its_own_claim(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('release')->once()->with(1);

        $job = new SendSequenceContactMailJob($user, [$contactMail->id], $contactMail->reserved_at);
        $job->setJob($queueJob);
        $job->release(1);

        $this->assertSame('2026-01-15 10:00:00', $contactMail->refresh()->reserved_at->toDateTimeString());

        Carbon::setTestNow(Carbon::parse('2026-01-15 10:05:00'));
        $this->mockMailerSending();

        $this->app->call([$job, 'handle']);

        $this->assertSame('sent', $contactMail->refresh()->status);
    }

    public function test_it_sends_a_claimed_mail_without_a_message_id(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $contactMail->forceFill(['message_id' => null])->save();

        $this->mockMailerSending();

        $this->runJob($user, $contactMail);

        $this->assertSame('sent', $contactMail->refresh()->status);
    }

    public function test_a_sent_mail_dispatches_sequence_step_sent_once_when_ses_reports_the_send_two_times(): void
    {
        Event::fake([SequenceStepSent::class]);
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $this->mockMailerSending();

        $this->runJob($user, $contactMail);

        $sesSend = $this->sesNotification([[
            'eventType' => 'Send',
            'mail' => ['tags' => ['contact_mail_id' => [(string) $contactMail->id]]],
        ]]);
        ProcessSesEventJob::dispatchSync($sesSend);
        ProcessSesEventJob::dispatchSync($sesSend);

        Event::assertDispatchedTimes(SequenceStepSent::class, 1);
        Event::assertDispatched(
            SequenceStepSent::class,
            fn (SequenceStepSent $event): bool => $event->sequenceStep->is($contactMail->sequenceStep) && $event->contact->is($contact),
        );
    }

    public function test_a_failed_send_does_not_dispatch_sequence_step_sent(): void
    {
        Event::fake([SequenceStepSent::class]);
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);
        $this->mockMailerThrowing(new RuntimeException('SES refused the mail.'));

        $this->runJob($user, $contactMail);

        $this->assertSame('failed', $contactMail->refresh()->status);
        Event::assertNotDispatched(SequenceStepSent::class);
    }

    public function test_it_uses_the_ses_sending_limiter_on_the_sequences_queue(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $job = new SendSequenceContactMailJob($user, [1], now());

        $this->assertSame('sequences', $job->queue);
        $this->assertInstanceOf(RateLimitedWithRedis::class, $job->middleware()[0]);
    }

    /**
     * Newsletter chunks and sequence mails of one user share the
     * `ses-sending` limiter. A large newsletter can hold a sequence job for
     * hours. The job must not expire while it waits for its turn.
     */
    public function test_a_job_that_the_limiter_holds_for_more_than_two_hours_still_sends(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contactMail = $this->reservedSequenceMail($user, $contact);

        $this->mockMailerSending();
        $this->refuseSesSendingSlots(30);
        dispatch(new SendSequenceContactMailJob($user, [$contactMail->id], $contactMail->reserved_at))->onConnection('database');

        for ($release = 1; $release <= 30; $release++) {
            $this->workOneSesSendingJob('sequences');
            $this->travel(5)->minutes();
        }

        $this->assertSame(30, DB::table('jobs')->value('attempts'));

        $this->workOneSesSendingJob('sequences');

        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseEmpty('failed_jobs');
        $this->assertSame('sent', $contactMail->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $stepAttributes
     * @param  array<string, mixed>  $messageAttributes
     */
    private function reservedSequenceMail(User $user, Contact $contact, array $stepAttributes = [], array $messageAttributes = []): ContactMail
    {
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
            ...$stepAttributes,
        ]);

        if ($messageAttributes !== []) {
            $step->message()->update($messageAttributes);
        }

        $contact->syncSequences([$sequence->id], true);

        $contactMail = ContactMail::query()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->firstOrFail();

        $contactMail->forceFill(['reserved_at' => now()->startOfSecond()])->save();

        return $contactMail;
    }

    private function mockMailerThrowing(Throwable $exception): void
    {
        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')->once()->andReturn($mailer);
        $mailer->shouldReceive('send')->once()->andThrow($exception);

        $this->app->instance(MailManager::class, $mailManager);
    }

    private function mockMailerSending(): void
    {
        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')->once()->andReturn($mailer);
        $mailer->shouldReceive('send')->once()->andReturnNull();

        $this->app->instance(MailManager::class, $mailManager);
    }

    private function runJob(User $user, ContactMail $contactMail, ?CarbonInterface $claimToken = null): void
    {
        $job = new SendSequenceContactMailJob($user, [$contactMail->id], $claimToken ?? $contactMail->reserved_at ?? now());

        $this->app->call([$job, 'handle']);
    }
}
