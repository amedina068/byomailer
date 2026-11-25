<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Mail\NewsletterMail;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Services\SesBulkEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

/**
 * A tenant owns its SNS topic and can publish any message on it. An event
 * from that topic must only change the mails of the client that owns the topic.
 */
class SesEventTenantIsolationTest extends TestCase
{
    use BuildsSesNotifications;
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_event_on_the_topic_of_one_tenant_cannot_change_a_mail_of_another_tenant(): void
    {
        $victimMail = $this->deliveredMail('victim@example.test');
        $attacker = User::factory()->create();
        $attackerTopic = $this->sesTopicArnFor($attacker->client_id);

        ProcessSesEventJob::dispatchSync($this->envelope($attackerTopic, [
            $this->bounceEvent(['contact_mail_id' => [(string) $victimMail->id]]),
        ]));

        $this->assertSame('delivered', $victimMail->refresh()->status);
        $this->assertNull(Contact::withoutGlobalScopes()->find($victimMail->contact_id)->unsubscribed_at);
        $this->assertDatabaseMissing('blacklisted_emails', ['email' => 'victim@example.test']);
    }

    public function test_uuid_tag_of_another_tenant_is_ignored(): void
    {
        $victimMail = $this->deliveredMail();
        $attacker = User::factory()->create();

        ProcessSesEventJob::dispatchSync($this->envelope($this->sesTopicArnFor($attacker->client_id), [
            $this->bounceEvent(['contact_mail_uuid' => [$victimMail->uuid]]),
        ]));

        $this->assertSame('delivered', $victimMail->refresh()->status);
    }

    public function test_event_from_a_topic_that_no_tenant_owns_is_ignored(): void
    {
        $mail = $this->deliveredMail();

        ProcessSesEventJob::dispatchSync($this->envelope('arn:aws:sns:us-east-1:222222222222:mailautomation-unknown', [
            $this->bounceEvent(['contact_mail_id' => [(string) $mail->id]]),
        ]));

        $this->assertSame('delivered', $mail->refresh()->status);
        $this->assertNull(Contact::withoutGlobalScopes()->find($mail->contact_id)->unsubscribed_at);
    }

    public function test_payload_without_an_sns_envelope_is_ignored(): void
    {
        $mail = $this->deliveredMail();
        $this->sesTopicArnFor($mail->client_id);

        ProcessSesEventJob::dispatchSync([
            'events' => [$this->bounceEvent(['contact_mail_id' => [(string) $mail->id]])],
        ]);

        $this->assertSame('delivered', $mail->refresh()->status);
        $this->assertNull(Contact::withoutGlobalScopes()->find($mail->contact_id)->unsubscribed_at);
    }

    public function test_uuid_tag_of_the_same_tenant_is_processed(): void
    {
        $mail = $this->deliveredMail();

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->bounceEvent(['contact_mail_uuid' => [$mail->uuid]]),
        ]));

        $this->assertSame('bounced', $mail->refresh()->status);
        $this->assertNotNull(Contact::withoutGlobalScopes()->find($mail->contact_id)->unsubscribed_at);
    }

    public function test_integer_tag_of_a_mail_sent_before_the_uuid_tag_is_still_processed(): void
    {
        $mail = $this->deliveredMail();

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->bounceEvent(['contact_mail_id' => [(string) $mail->id]]),
        ]));

        $this->assertSame('bounced', $mail->refresh()->status);
    }

    public function test_no_such_user_bounce_blacklists_the_address_for_the_client_only(): void
    {
        $mail = $this->deliveredMail('gone@example.test');
        $contact = Contact::withoutGlobalScopes()->find($mail->contact_id);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->bounceEvent(['contact_mail_uuid' => [$mail->uuid]]),
        ]));

        $this->assertDatabaseHas('blacklisted_emails', [
            'email' => 'gone@example.test',
            'user_id' => $contact->user_id,
            'client_id' => $contact->client_id,
            'reason' => 'bounce',
        ]);
        $this->assertFalse(BlacklistedEmail::withoutGlobalScopes()->whereNull('client_id')->exists());
    }

    public function test_newsletter_mail_tags_the_contact_mail_uuid(): void
    {
        $mail = $this->deliveredMail();
        $this->actingAsTenant($mail->client_id);

        $metadata = (new NewsletterMail($mail))->envelope()->metadata;

        $this->assertSame($mail->uuid, $metadata['contact_mail_uuid']);
        $this->assertArrayNotHasKey('contact_mail_id', $metadata);
    }

    public function test_bulk_send_tags_the_uuid_and_marks_the_rejected_entry_failed(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update(['email_identity_id' => $identity->id]);
        $contactMail = ContactMail::factory()->for($contact)->for($newsletter, 'mailable')
            ->create(['status' => 'pending', 'message_id' => $newsletter->message->id]);

        $capturedDestinations = null;
        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldReceive('sendBulkTemplatedEmail')
            ->once()
            ->withArgs(function ($template, $from, $fromName, $destinations) use (&$capturedDestinations): bool {
                $capturedDestinations = $destinations;

                return true;
            })
            ->andReturn([['Status' => 'MESSAGE_REJECTED', 'Error' => 'Rejected']]);
        $this->app->instance(SesBulkEmailService::class, $ses);

        app(NewsletterTransport::class)->sendBatch(
            'tpl-123',
            $identity->email,
            'Sender',
            collect([$contact]),
            collect([$contactMail]),
            $user,
            [],
            'byomailer',
            $newsletter->message,
        );

        $this->assertSame([['Name' => 'contact_mail_uuid', 'Value' => $contactMail->uuid]], $capturedDestinations[0]['tags']);
        $this->assertSame('failed', $contactMail->refresh()->status);
    }

    private function deliveredMail(?string $email = null): ContactMail
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(array_filter(['email' => $email]));
        $newsletter = Newsletter::factory()->for($user)->create();

        return ContactMail::factory()->for($contact)->for($newsletter, 'mailable')->create(['status' => 'delivered', 'template_data' => []]);
    }

    /**
     * @param  array<string, array<int, string>>  $tags
     * @return array<string, mixed>
     */
    private function bounceEvent(array $tags): array
    {
        return [
            'eventType' => 'Bounce',
            'mail' => ['tags' => $tags],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'NoEmail',
                'timestamp' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, string>
     */
    private function envelope(string $topicArn, array $events): array
    {
        return [
            'Type' => 'Notification',
            'MessageId' => fake()->uuid(),
            'TopicArn' => $topicArn,
            'Message' => json_encode(['events' => $events]),
        ];
    }
}
