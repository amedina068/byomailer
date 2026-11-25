<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\SequenceStepScheduler;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class SesEventIdempotencyTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    public function test_a_duplicate_click_notification_adds_one_click_row(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail();
        $notification = $this->sesNotification([$this->clickEvent($contactMail, 'https://example.com/product', now()->addMilliseconds(250))]);

        ProcessSesEventJob::dispatchSync($notification);
        ProcessSesEventJob::dispatchSync($notification);

        $this->assertDatabaseCount('contact_mail_clicks', 1);
    }

    public function test_two_different_clicks_on_one_mail_add_two_click_rows(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail();

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->clickEvent($contactMail, 'https://example.com/product', now()),
            $this->clickEvent($contactMail, 'https://example.com/product', now()->addMinute()),
            $this->clickEvent($contactMail, 'https://example.com/pricing', now()),
        ]));

        $this->assertDatabaseCount('contact_mail_clicks', 3);
    }

    public function test_a_duplicate_soft_bounce_notification_adds_one_to_the_soft_bounce_count(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contact = Contact::factory()->for(User::factory())->create();
        $contactMail = $this->makeMailFor($contact);
        $notification = $this->sesNotification([$this->softBounceEvent($contactMail)]);

        foreach (range(1, ProcessSesEventJob::SOFT_BOUNCE_LIMIT) as $delivery) {
            ProcessSesEventJob::dispatchSync($notification);
        }

        $contact->refresh();
        $this->assertSame(1, $contact->soft_bounce_count);
        $this->assertNull($contact->unsubscribed_at);
        $this->assertDatabaseMissing('blacklisted_emails', ['email' => $contact->email]);

        $contactMail->refresh();
        $this->assertSame('bounced', $contactMail->status);
        $this->assertEquals(now(), $contactMail->bounced_at);
    }

    public function test_a_retry_after_a_failed_event_does_not_count_the_soft_bounce_again(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contact = Contact::factory()->for(User::factory())->create();
        $bounced = $this->makeMailFor($contact);
        $failing = $this->makeMailFor($contact);
        $this->failSchedulerFor([$failing]);

        $notification = $this->sesNotification([
            $this->softBounceEvent($bounced),
            $this->deliveryEvent($failing),
        ]);

        foreach ([1, 2] as $attempt) {
            $this->assertThrows(
                fn () => ProcessSesEventJob::dispatchSync($notification),
                RuntimeException::class,
                "The scheduler failed for mail {$failing->id}.",
            );
        }

        $this->assertSame(1, $contact->refresh()->soft_bounce_count);
    }

    public function test_soft_bounces_on_two_mails_add_two_to_the_soft_bounce_count(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contact = Contact::factory()->for(User::factory())->create();

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->softBounceEvent($this->makeMailFor($contact))]));
        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->softBounceEvent($this->makeMailFor($contact))]));

        $this->assertSame(2, $contact->refresh()->soft_bounce_count);
    }

    /**
     * The suppression list has one row for each client and email. The author
     * of a contact can change, for example when a member deletes the account
     * and a teammate gets the contacts. A later complaint must find the row of
     * the client and must not try to insert a second row.
     */
    public function test_a_complaint_uses_the_suppression_row_of_the_client_when_another_member_created_it(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $author = User::factory()->create();
        $teammate = User::factory()->teammateOf($author)->create();
        $contact = Contact::factory()->for($author)->create();
        BlacklistedEmail::factory()->for($teammate)->create([
            'client_id' => $author->client_id,
            'email' => $contact->email,
            'reason' => 'soft_bounce',
        ]);
        $contactMail = $this->makeMailFor($contact);

        ProcessSesEventJob::dispatchSync($this->sesNotification([[
            'eventType' => 'Complaint',
            'mail' => ['tags' => ['contact_mail_id' => [(string) $contactMail->id]]],
            'complaint' => ['timestamp' => now()->toISOString()],
        ]]));

        $this->assertNotNull($contact->refresh()->unsubscribed_at);
        $this->assertSame('complained', $contactMail->refresh()->status);
        $this->assertSame(1, BlacklistedEmail::withoutGlobalScopes()->where('email', $contact->email)->count());
    }

    public function test_a_failing_event_does_not_block_the_next_event_and_fails_the_job_for_a_retry(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contact = Contact::factory()->for(User::factory())->create();
        $failing = $this->makeMailFor($contact);
        $next = $this->makeMailFor($contact);
        $this->failSchedulerFor([$failing]);

        $notification = $this->sesNotification([
            $this->deliveryEvent($failing),
            $this->deliveryEvent($next),
        ]);

        $this->assertThrows(
            fn () => ProcessSesEventJob::dispatchSync($notification),
            RuntimeException::class,
            "The scheduler failed for mail {$failing->id}.",
        );

        $this->assertSame('delivered', $next->refresh()->status);

        $failing->refresh();
        $this->assertSame('sent', $failing->status);
        $this->assertNull($failing->delivered_at);
    }

    public function test_the_job_rethrows_the_first_failure_and_reports_the_later_failures(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contact = Contact::factory()->for(User::factory())->create();
        $first = $this->makeMailFor($contact);
        $applied = $this->makeMailFor($contact);
        $second = $this->makeMailFor($contact);
        $this->failSchedulerFor([$first, $second]);
        Exceptions::fake();

        $notification = $this->sesNotification([
            $this->deliveryEvent($first),
            $this->deliveryEvent($applied),
            $this->deliveryEvent($second),
        ]);

        $this->assertThrows(
            fn () => ProcessSesEventJob::dispatchSync($notification),
            RuntimeException::class,
            "The scheduler failed for mail {$first->id}.",
        );

        $this->assertSame('delivered', $applied->refresh()->status);
        $this->assertSame('sent', $second->refresh()->status);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === "The scheduler failed for mail {$second->id}.");
        Exceptions::assertNotReported(fn (RuntimeException $exception): bool => $exception->getMessage() === "The scheduler failed for mail {$first->id}.");
    }

    /**
     * @param  list<ContactMail>  $failingMails
     */
    private function failSchedulerFor(array $failingMails): void
    {
        $this->mock(SequenceStepScheduler::class)
            ->shouldReceive('scheduleNextStep')
            ->andReturnUsing(function (ContactMail $contactMail) use ($failingMails): void {
                foreach ($failingMails as $failingMail) {
                    if ($contactMail->is($failingMail)) {
                        throw new RuntimeException("The scheduler failed for mail {$contactMail->id}.");
                    }
                }
            });
    }

    private function makeMail(): ContactMail
    {
        return $this->makeMailFor(Contact::factory()->for(User::factory())->create());
    }

    private function makeMailFor(Contact $contact): ContactMail
    {
        $newsletter = Newsletter::factory()->create([
            'user_id' => $contact->user_id,
            'client_id' => $contact->client_id,
        ]);

        return ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'delivered_at' => null,
                'opened_at' => null,
                'clicked_at' => null,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clickEvent(ContactMail $contactMail, string $link, CarbonInterface $timestamp): array
    {
        return [
            'eventType' => 'Click',
            'mail' => ['tags' => ['contact_mail_id' => [(string) $contactMail->id]]],
            'click' => ['timestamp' => $timestamp->toISOString(), 'link' => $link],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function softBounceEvent(ContactMail $contactMail): array
    {
        return [
            'eventType' => 'Bounce',
            'mail' => ['tags' => ['contact_mail_id' => [(string) $contactMail->id]]],
            'bounce' => [
                'feedbackId' => 'feedback-1',
                'bounceType' => 'Transient',
                'bounceSubType' => 'MailboxFull',
                'timestamp' => now()->addMilliseconds(250)->toISOString(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryEvent(ContactMail $contactMail): array
    {
        return [
            'eventType' => 'Delivery',
            'mail' => ['tags' => ['contact_mail_id' => [(string) $contactMail->id]]],
            'delivery' => ['timestamp' => now()->toISOString()],
        ];
    }
}
