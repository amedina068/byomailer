<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class SesWebhookTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    public function test_delivery_event_updates_contact_mail(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'delivered_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('DELIVERY', $contactMail->id, [
                'delivery' => ['timestamp' => now()->toISOString()],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('delivered', $contactMail->status);
        $this->assertEquals(now()->toISOString(), optional($contactMail->delivered_at)->toISOString());
    }

    public function test_click_event_updates_contact_mail(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'opened_at' => null,
                'clicked_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('CLICK', $contactMail->id, [
                'click' => [
                    'timestamp' => now()->toISOString(),
                    'link' => 'https://example.com/product',
                ],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('opened', $contactMail->status);
        $this->assertEquals(now()->toISOString(), optional($contactMail->opened_at)->toISOString());
        $this->assertEquals(now()->toISOString(), optional($contactMail->clicked_at)->toISOString());
        $this->assertDatabaseHas('contact_mail_clicks', [
            'contact_mail_id' => $contactMail->id,
            'contact_id' => $contact->id,
            'link' => 'https://example.com/product',
        ]);
    }

    public function test_bounce_event_blacklists_contact(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'bounced_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('BOUNCE', $contactMail->id, [
                'bounce' => [
                    'timestamp' => now()->toISOString(),
                    'bounceType' => 'Permanent',
                ],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('bounced', $contactMail->status);
        $this->assertEquals(now()->toISOString(), optional($contactMail->bounced_at)->toISOString());

        $this->assertDatabaseHas('blacklisted_emails', [
            'user_id' => $user->id,
            'email' => $contact->email,
        ]);

        $contact->refresh();
        $this->assertEquals(now()->toISOString(), optional($contact->unsubscribed_at)->toISOString());
    }

    public function test_soft_bounce_does_not_blacklist_contact(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'bounced_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('BOUNCE', $contactMail->id, [
                'bounce' => [
                    'timestamp' => now()->toISOString(),
                    'bounceType' => 'Transient',
                    'bounceSubType' => 'MailboxFull',
                ],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('bounced', $contactMail->status);
        $this->assertEquals(now()->toISOString(), optional($contactMail->bounced_at)->toISOString());

        $this->assertDatabaseMissing('blacklisted_emails', [
            'email' => $contact->email,
        ]);

        $contact->refresh();
        $this->assertNull($contact->unsubscribed_at);
    }

    public function test_permanent_bounce_for_nonexistent_email_blacklists_for_the_client_only(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['email' => 'missing@example.com']);
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('BOUNCE', $contactMail->id, [
                'bounce' => [
                    'timestamp' => now()->toISOString(),
                    'bounceType' => 'Permanent',
                    'bounceSubType' => 'General',
                    'bouncedRecipients' => [
                        [
                            'diagnosticCode' => 'smtp; 550 5.1.1 recipient does not exist',
                        ],
                    ],
                ],
            ]),
        ]));

        $this->assertDatabaseHas('blacklisted_emails', [
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'email' => 'missing@example.com',
        ]);
        $this->assertDatabaseMissing('blacklisted_emails', [
            'client_id' => null,
            'email' => 'missing@example.com',
        ]);
    }

    public function test_reject_event_does_not_suppress_the_contact(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        [$contact, $contactMail] = $this->makeContactMail();

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('REJECT', $contactMail->id, [
                'reject' => [
                    'timestamp' => now()->toISOString(),
                    'reason' => 'Bad content',
                ],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('rejected', $contactMail->status);
        $this->assertEquals(now()->toISOString(), optional($contactMail->rejected_at)->toISOString());

        $contact->refresh();
        $this->assertNull($contact->unsubscribed_at);
        $this->assertDatabaseMissing('blacklisted_emails', [
            'email' => $contact->email,
        ]);
    }

    public function test_undetermined_bounce_is_soft(): void
    {
        [$contact, $contactMail] = $this->makeContactMail();

        $this->dispatchBounce($contactMail->id, 'Undetermined');

        $this->assertSame('bounced', $contactMail->refresh()->status);
        $this->assertNull($contact->refresh()->unsubscribed_at);
        $this->assertDatabaseMissing('blacklisted_emails', [
            'email' => $contact->email,
        ]);
    }

    public function test_unknown_bounce_type_is_soft(): void
    {
        [$contact, $contactMail] = $this->makeContactMail();

        $this->dispatchBounce($contactMail->id, null);

        $this->assertSame('bounced', $contactMail->refresh()->status);
        $this->assertNull($contact->refresh()->unsubscribed_at);
        $this->assertDatabaseMissing('blacklisted_emails', [
            'email' => $contact->email,
        ]);
    }

    public function test_three_soft_bounces_suppress_the_contact(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        [$contact, $contactMail] = $this->makeContactMail();

        $this->dispatchBounce($contactMail->id, 'Transient');
        $this->dispatchBounce($this->makeMailFor($contact)->id, 'Transient');

        $this->assertNull($contact->refresh()->unsubscribed_at);
        $this->assertSame(2, $contact->soft_bounce_count);

        $this->dispatchBounce($this->makeMailFor($contact)->id, 'Undetermined');

        $contact->refresh();
        $this->assertSame(3, $contact->soft_bounce_count);
        $this->assertEquals(now()->toISOString(), optional($contact->unsubscribed_at)->toISOString());
        $this->assertDatabaseHas('blacklisted_emails', [
            'user_id' => $contact->user_id,
            'client_id' => $contact->client_id,
            'email' => $contact->email,
            'reason' => 'soft_bounce',
        ]);
    }

    public function test_delivery_resets_the_soft_bounce_count(): void
    {
        [$contact, $contactMail] = $this->makeContactMail();
        $contact->forceFill(['soft_bounce_count' => 2])->save();

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('DELIVERY', $contactMail->id, [
                'delivery' => ['timestamp' => now()->toISOString()],
            ]),
        ]));

        $this->assertSame(0, $contact->refresh()->soft_bounce_count);
    }

    public function test_soft_bounce_count_does_not_carry_across_a_delivery(): void
    {
        [$contact, $contactMail] = $this->makeContactMail();

        $this->dispatchBounce($contactMail->id, 'Transient');
        $this->dispatchBounce($this->makeMailFor($contact)->id, 'Transient');

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('DELIVERY', $this->makeMailFor($contact)->id, [
                'delivery' => ['timestamp' => now()->toISOString()],
            ]),
        ]));

        $this->dispatchBounce($this->makeMailFor($contact)->id, 'Transient');
        $this->dispatchBounce($this->makeMailFor($contact)->id, 'Transient');

        $contact->refresh();
        $this->assertSame(2, $contact->soft_bounce_count);
        $this->assertNull($contact->unsubscribed_at);
        $this->assertDatabaseMissing('blacklisted_emails', [
            'email' => $contact->email,
        ]);
    }

    public function test_soft_bounce_count_does_not_touch_another_clients_contact(): void
    {
        [$contact, $contactMail] = $this->makeContactMail();
        $otherContact = Contact::factory()
            ->for(User::factory()->create())
            ->create(['email' => $contact->email, 'soft_bounce_count' => 1]);
        $this->assertNotSame($contact->client_id, $otherContact->client_id);

        $this->dispatchBounce($contactMail->id, 'Transient');
        $this->dispatchBounce($this->makeMailFor($contact)->id, 'Transient');
        $this->dispatchBounce($this->makeMailFor($contact)->id, 'Transient');

        $this->assertNotNull($contact->refresh()->unsubscribed_at);

        $otherContact->refresh();
        $this->assertSame(1, $otherContact->soft_bounce_count);
        $this->assertNull($otherContact->unsubscribed_at);
        $this->assertDatabaseMissing('blacklisted_emails', [
            'client_id' => $otherContact->client_id,
            'email' => $otherContact->email,
        ]);
        $this->assertDatabaseMissing('blacklisted_emails', [
            'client_id' => null,
            'email' => $contact->email,
        ]);
    }

    public function test_sns_notification_payload_is_processed(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'delivered_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync([
            'Type' => 'Notification',
            'TopicArn' => $this->sesTopicArnFor($user->client_id),
            'MessageId' => 'msg-1',
            'Message' => json_encode(
                $this->eventPayload('DELIVERY', $contactMail->id, [
                    'delivery' => ['timestamp' => now()->toISOString()],
                ])
            ),
        ]);

        $contactMail->refresh();
        $this->assertSame('delivered', $contactMail->status);
    }

    public function test_subscription_confirmation_is_logged_and_skipped(): void
    {
        ProcessSesEventJob::dispatchSync([
            'Type' => 'SubscriptionConfirmation',
            'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation',
            'MessageId' => 'msg-2',
            'SubscribeURL' => 'https://sns.amazonaws.com/confirm?id=123',
        ]);

        // No exception thrown — confirmation is just logged and skipped
        $this->assertTrue(true);
    }

    public function test_delivery_event_backfills_sent_at_when_empty(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'pending',
                'sent_at' => null,
                'delivered_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('DELIVERY', $contactMail->id, [
                'delivery' => ['timestamp' => now()->toISOString()],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('delivered', $contactMail->status);
        $this->assertEquals(now()->toISOString(), optional($contactMail->sent_at)->toISOString());
        $this->assertEquals(now()->toISOString(), optional($contactMail->delivered_at)->toISOString());
    }

    public function test_delivery_event_does_not_overwrite_existing_sent_at(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $originalSentAt = now()->subMinutes(2);
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'sent_at' => $originalSentAt,
                'delivered_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('DELIVERY', $contactMail->id, [
                'delivery' => ['timestamp' => now()->toISOString()],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertEquals($originalSentAt->toISOString(), $contactMail->sent_at->toISOString());
    }

    public function test_open_event_backfills_sent_at_and_delivered_at_when_empty(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'pending',
                'sent_at' => null,
                'delivered_at' => null,
                'opened_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('OPEN', $contactMail->id, [
                'open' => ['timestamp' => now()->toISOString()],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('opened', $contactMail->status);
        $this->assertEquals(now()->toISOString(), optional($contactMail->sent_at)->toISOString());
        $this->assertEquals(now()->toISOString(), optional($contactMail->delivered_at)->toISOString());
        $this->assertEquals(now()->toISOString(), optional($contactMail->opened_at)->toISOString());
    }

    public function test_click_event_backfills_sent_at_and_delivered_at_when_empty(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'pending',
                'sent_at' => null,
                'delivered_at' => null,
                'opened_at' => null,
                'clicked_at' => null,
            ]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('CLICK', $contactMail->id, [
                'click' => [
                    'timestamp' => now()->toISOString(),
                    'link' => 'https://example.com',
                ],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('opened', $contactMail->status);
        $this->assertEquals(now()->toISOString(), optional($contactMail->sent_at)->toISOString());
        $this->assertEquals(now()->toISOString(), optional($contactMail->delivered_at)->toISOString());
        $this->assertEquals(now()->toISOString(), optional($contactMail->opened_at)->toISOString());
        $this->assertEquals(now()->toISOString(), optional($contactMail->clicked_at)->toISOString());
    }

    private function dispatchBounce(int $contactMailId, ?string $bounceType): void
    {
        $bounce = ['timestamp' => now()->toISOString()];

        if ($bounceType !== null) {
            $bounce['bounceType'] = $bounceType;
        }

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->eventPayload('BOUNCE', $contactMailId, ['bounce' => $bounce]),
        ]));
    }

    private function makeMailFor(Contact $contact): ContactMail
    {
        $newsletter = Newsletter::factory()->for($contact->user)->create();

        return ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);
    }

    /**
     * @return array{0: Contact, 1: ContactMail}
     */
    private function makeContactMail(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);

        return [$contact, $contactMail];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(string $type, int $contactMailId, array $overrides = []): array
    {
        return array_merge([
            'eventType' => $type,
            'mail' => [
                'tags' => [
                    'contact_mail_id' => [$contactMailId],
                ],
            ],
            'timestamp' => now()->toISOString(),
        ], $overrides);
    }
}
