<?php

namespace Tests\Feature;

use App\Console\Commands\SendDueSequenceStepContactMails;
use App\Enums\SequenceStatus;
use App\Jobs\SendSequenceContactMailJob;
use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

class SendDueSequenceStepContactMailsCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_claims_due_mails_and_dispatches_one_job_per_user_without_sending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        Bus::fake();

        [$user, $mails] = $this->dueSequenceMails(2);
        EmailCredential::factory()->for($user)->create(['max_send_rate' => 14]);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Bus::assertDispatchedTimes(SendSequenceContactMailJob::class, 1);
        Bus::assertDispatched(SendSequenceContactMailJob::class, fn (SendSequenceContactMailJob $job): bool => $job->user->is($user)
            && $job->contactMailIds == $mails->pluck('id')->all()
            && $job->claimToken->toDateTimeString() === '2026-01-15 10:00:00');

        foreach ($mails as $mail) {
            $mail->refresh();
            $this->assertSame('pending', $mail->status);
            $this->assertSame('2026-01-15 10:00:00', $mail->reserved_at->toDateTimeString());
        }
    }

    public function test_a_paused_sequence_or_step_keeps_its_due_mails_out_of_the_tick(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        Bus::fake();

        [$user, $pausedSequenceMails] = $this->dueSequenceMails(1);
        $pausedSequenceMails->first()->sequenceStep->sequence()->withoutGlobalScopes()->update(['status' => SequenceStatus::Draft]);
        [, $pausedStepMails] = $this->dueSequenceMails(1);
        $pausedStepMails->first()->sequenceStep->update(['status' => SequenceStatus::Draft]);
        [, $liveMails] = $this->dueSequenceMails(1, ['scheduled_at' => now()->subSecond()]);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Bus::assertDispatchedTimes(SendSequenceContactMailJob::class, 1);
        Bus::assertDispatched(SendSequenceContactMailJob::class, fn (SendSequenceContactMailJob $job): bool => $job->contactMailIds == $liveMails->pluck('id')->all());

        foreach ([...$pausedSequenceMails, ...$pausedStepMails] as $mail) {
            $mail = $mail->fresh();
            $this->assertSame('pending', $mail->status);
            $this->assertNull($mail->reserved_at);
        }
    }

    public function test_a_resumed_sequence_sends_the_mails_that_came_due_during_the_pause(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        Bus::fake();

        [, $mails] = $this->dueSequenceMails(1);
        $sequence = $mails->first()->sequenceStep->sequence()->withoutGlobalScopes()->first();
        $sequence->update(['status' => SequenceStatus::Draft]);
        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);
        Bus::assertNotDispatched(SendSequenceContactMailJob::class);

        $sequence->update(['status' => 'live']);
        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Bus::assertDispatched(SendSequenceContactMailJob::class, fn (SendSequenceContactMailJob $job): bool => $job->contactMailIds == $mails->pluck('id')->all());
    }

    public function test_fresh_reservation_is_not_reclaimed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        Bus::fake();

        [, $mails] = $this->dueSequenceMails(1, ['reserved_at' => now()->subMinute()]);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Bus::assertNotDispatched(SendSequenceContactMailJob::class);
        $this->assertSame('2026-01-15 09:59:00', $mails->first()->refresh()->reserved_at->toDateTimeString());
    }

    public function test_stale_reservation_is_reclaimed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        Bus::fake();

        [, $mails] = $this->dueSequenceMails(1, ['reserved_at' => now()->subMinutes(16)]);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Bus::assertDispatchedTimes(SendSequenceContactMailJob::class, 1);
        $this->assertSame('2026-01-15 10:00:00', $mails->first()->refresh()->reserved_at->toDateTimeString());
    }

    public function test_dispatch_failure_clears_the_claim(): void
    {
        [, $mails] = $this->dueSequenceMails(1);

        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis is down'));
        $this->app->instance(Dispatcher::class, $bus);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $mail = $mails->first()->refresh();
        $this->assertNull($mail->reserved_at);
        $this->assertSame('pending', $mail->status);
    }

    public function test_tick_claims_at_most_the_limit(): void
    {
        Bus::fake();

        $this->dueSequenceMails(250);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame(SendDueSequenceStepContactMails::TICK_LIMIT, ContactMail::withoutGlobalScopes()->whereNotNull('reserved_at')->count());
        $this->assertSame(
            SendDueSequenceStepContactMails::TICK_LIMIT,
            Bus::dispatched(SendSequenceContactMailJob::class)->sum(fn (SendSequenceContactMailJob $job): int => count($job->contactMailIds)),
        );
    }

    public function test_tick_claims_the_oldest_due_rows_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        Bus::fake();

        [, $mails] = $this->dueSequenceMails(SendDueSequenceStepContactMails::TICK_LIMIT + 1);

        $newest = $mails->first();
        $newest->forceFill(['scheduled_at' => now()->subMinute()])->save();
        $mails->skip(1)->values()->each(fn (ContactMail $mail, int $index) => $mail
            ->forceFill(['scheduled_at' => now()->subMinutes(10 + $index)])->save());
        $oldest = $mails->last();

        // The due index already returns rows by scheduled_at. Drop it so SQLite
        // reads rows in id order, and only the ORDER BY puts the oldest first.
        Schema::table('contact_mails', fn (Blueprint $table) => $table->dropIndex('contact_mails_sequence_due_index'));

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertNull($newest->refresh()->reserved_at);
        $this->assertNotNull($oldest->refresh()->reserved_at);
    }

    public function test_it_skips_unsubscribed_contacts_and_claims_rows_without_a_message(): void
    {
        Bus::fake();

        [$user, $mails] = $this->dueSequenceMails(2);
        Tenant::run($user->client_id, fn () => $mails[0]->contact->forceFill(['unsubscribed_at' => now()])->save());
        $mails[1]->forceFill(['message_id' => null])->save();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Bus::assertDispatchedTimes(SendSequenceContactMailJob::class, 1);
        Bus::assertDispatched(SendSequenceContactMailJob::class, fn (SendSequenceContactMailJob $job): bool => $job->contactMailIds === [$mails[1]->id]);
    }

    public function test_it_skips_sunset_contacts(): void
    {
        Bus::fake();

        [$user, $mails] = $this->dueSequenceMails(2);
        Tenant::run($user->client_id, fn () => $mails[0]->contact->forceFill(['sunset_at' => now()])->save());

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Bus::assertDispatched(SendSequenceContactMailJob::class, fn (SendSequenceContactMailJob $job): bool => $job->contactMailIds === [$mails[1]->id]);
        $this->assertNull($mails[0]->fresh()->reserved_at);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: Collection<int, ContactMail>}
     */
    private function dueSequenceMails(int $count, array $attributes = []): array
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $messageId = $step->message()->withoutGlobalScopes()->value('id');

        $mails = Contact::factory()->count($count)->for($user)->create()
            ->map(fn (Contact $contact): ContactMail => ContactMail::factory()->forSequenceStep($step)->pending()->create([
                'contact_id' => $contact->id,
                'message_id' => $messageId,
                'scheduled_at' => now()->subMinute(),
                ...$attributes,
            ]));

        return [$user, $mails];
    }

    public function test_second_tick_does_not_resend_a_sent_sequence_mail(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->first();

        $this->assertNotNull($contactMail);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $sendCount = 0;
        $mailManager->shouldReceive('build')->andReturn($mailer);
        $mailer->shouldReceive('send')->andReturnUsing(function () use (&$sendCount): null {
            $sendCount++;

            return null;
        });

        $this->app->instance(MailManager::class, $mailManager);
        $this->withoutSesSendingLimit();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Carbon::setTestNow(Carbon::parse('2026-01-15 10:01:00'));

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        $this->assertSame(1, $sendCount);

        $contactMail->refresh();
        $this->assertSame('sent', $contactMail->status);
        $this->assertSame('2026-01-15 10:00:00', $contactMail->sent_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_newsletter_mail_replaces_template_variables_in_content(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create([
            'email' => 'taylor@example.com',
            'extra_attributes' => ['first_name' => 'Taylor'],
        ]);

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        $step->message()->update([
            'html_content' => '<p>Hello {{ first_name }}, your email is {{ email }}.</p>',
            'text_content' => 'Hello {{ first_name }}, your email is {{ email }}.',
        ]);

        $contactMail = ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'template_data' => [
                'first_name' => 'Taylor',
                'email' => 'taylor@example.com',
            ],
        ]);

        $mailable = new NewsletterMail($contactMail);
        $rendered = $mailable->render();

        $this->assertStringContainsString('Hello Taylor, your email is taylor@example.com.', $rendered);
        $this->assertStringNotContainsString('{{ first_name }}', $rendered);
        $this->assertStringNotContainsString('{{ email }}', $rendered);

        Carbon::setTestNow();
    }

    public function test_newsletter_mail_always_replaces_unsubscribe_url_template_variable(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        $step->message()->update([
            'html_content' => '<p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'text_content' => 'Unsubscribe: {{ unsubscribe_url }}',
        ]);

        $contactMail = ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'template_data' => [],
        ]);

        $mailable = new NewsletterMail($contactMail);
        $rendered = $mailable->render();

        $this->assertStringContainsString($contactMail->unsubscribe_link, $rendered);
        $this->assertStringNotContainsString('{{ unsubscribe_url }}', $rendered);
    }
}
