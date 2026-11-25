<?php

namespace Tests\Feature;

use App\Mail\SequenceBlockedDigestMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\SesPermissionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendSequenceBlockedDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_digest_when_sequence_mails_are_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        ContactMail::factory()
            ->for($contact, 'contact')
            ->forSequenceStep($step)
            ->create([
                'status' => 'failed',
                'failure_reason' => SesPermissionChecker::FAILURE_REASON,
                'sent_at' => null,
            ]);

        $this->artisan('ses:send-sequence-blocked-digest')->assertSuccessful();

        Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) use ($user) {
            return $job->mailable instanceof SequenceBlockedDigestMail
                && $job->mailable->recipient->is($user)
                && $job->mailable->blockedCount === 1;
        });
    }

    public function test_the_digest_counts_all_waiting_emails_and_not_only_today(): void
    {
        $mail = new SequenceBlockedDigestMail(User::factory()->create(), 3);

        $mail->assertSeeInHtml('3</strong> sequence emails are waiting', false);
        $mail->assertDontSeeInHtml('today');
    }

    public function test_it_skips_users_with_send_permission(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => true,
            'permissions_checked_at' => now(),
        ]);

        $this->artisan('ses:send-sequence-blocked-digest')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);
    }

    public function test_it_skips_users_with_no_blocked_mails(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $this->artisan('ses:send-sequence-blocked-digest')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);
    }

    public function test_it_skips_credentials_without_permissions_checked(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => null,
        ]);

        $this->artisan('ses:send-sequence-blocked-digest')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);
    }

    public function test_it_ignores_mails_failed_for_other_reasons(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create();

        ContactMail::factory()
            ->for($contact, 'contact')
            ->forSequenceStep($step)
            ->create([
                'status' => 'failed',
                'failure_reason' => 'RENDERING_FAILURE',
                'sent_at' => null,
            ]);

        $this->artisan('ses:send-sequence-blocked-digest')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);
    }
}
