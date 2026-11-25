<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailLog;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class EmailLogSnsUpdateTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    public function test_delivery_event_updates_email_log_status_and_sns_payload(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);

        $messageId = 'ses-msg-'.fake()->uuid();

        EmailLog::factory()->create([
            'client_id' => $user->client_id,
            'operation' => 'SendBulkEmail',
            'recipient' => $contact->email,
            'message_id' => $messageId,
            'status' => 'sent',
        ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            [
                'eventType' => 'DELIVERY',
                'mail' => [
                    'messageId' => $messageId,
                    'tags' => [
                        'contact_mail_id' => [$contactMail->id],
                    ],
                ],
                'delivery' => ['timestamp' => now()->toISOString()],
            ],
        ]));

        $this->assertDatabaseHas('email_logs', [
            'message_id' => $messageId,
            'status' => 'delivered',
        ]);

        $log = EmailLog::withoutGlobalScopes()->where('message_id', $messageId)->first();
        $this->assertNotNull($log->sns_payload);
        $this->assertSame('DELIVERY', $log->sns_payload['eventType']);
    }

    public function test_bounce_event_updates_email_log_status(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);

        $messageId = 'ses-msg-'.fake()->uuid();

        EmailLog::factory()->create([
            'client_id' => $user->client_id,
            'message_id' => $messageId,
            'status' => 'sent',
        ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            [
                'eventType' => 'BOUNCE',
                'mail' => [
                    'messageId' => $messageId,
                    'tags' => [
                        'contact_mail_id' => [$contactMail->id],
                    ],
                ],
                'bounce' => ['timestamp' => now()->toISOString()],
            ],
        ]));

        $this->assertDatabaseHas('email_logs', [
            'message_id' => $messageId,
            'status' => 'bounced',
        ]);
    }

    public function test_event_without_message_id_does_not_fail(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            [
                'eventType' => 'DELIVERY',
                'mail' => [
                    'tags' => [
                        'contact_mail_id' => [$contactMail->id],
                    ],
                ],
                'delivery' => ['timestamp' => now()->toISOString()],
            ],
        ]));

        $contactMail->refresh();
        $this->assertSame('delivered', $contactMail->status);
    }

    public function test_sns_notification_updates_email_log(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);

        $messageId = 'ses-msg-'.fake()->uuid();

        EmailLog::factory()->create([
            'client_id' => $user->client_id,
            'message_id' => $messageId,
            'status' => 'sent',
        ]);

        ProcessSesEventJob::dispatchSync([
            'Type' => 'Notification',
            'TopicArn' => $this->sesTopicArnFor($user->client_id),
            'MessageId' => 'sns-msg-1',
            'Message' => json_encode([
                'eventType' => 'DELIVERY',
                'mail' => [
                    'messageId' => $messageId,
                    'tags' => [
                        'contact_mail_id' => [$contactMail->id],
                    ],
                ],
                'delivery' => ['timestamp' => now()->toISOString()],
            ]),
        ]);

        $this->assertDatabaseHas('email_logs', [
            'message_id' => $messageId,
            'status' => 'delivered',
        ]);
    }
}
