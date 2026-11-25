<?php

namespace Tests\Feature\Webhooks;

use App\Events\SequenceStepSent;
use App\Jobs\ProcessSesEventJob;
use App\Models\ContactMail;
use App\Services\GeoIpService;
use App\Services\SequenceStepScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class SequenceStepSentEventTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    private function process(int $contactMailId, string $eventType): void
    {
        $payload = [
            'Type' => 'Notification',
            'TopicArn' => $this->sesTopicArnFor(ContactMail::withoutGlobalScopes()->findOrFail($contactMailId)->client_id),
            'Message' => json_encode([
                'eventType' => $eventType,
                'mail' => ['tags' => ['contact_mail_id' => [(string) $contactMailId]]],
            ]),
        ];

        (new ProcessSesEventJob($payload))->handle(
            app(SequenceStepScheduler::class),
            app(GeoIpService::class),
        );
    }

    public function test_ses_send_event_for_a_pending_sequence_mail_does_not_dispatch_the_event(): void
    {
        Event::fake([SequenceStepSent::class]);
        $mail = ContactMail::factory()->forSequenceStep()->create();

        $this->process($mail->id, 'SEND');

        $this->assertSame('sent', $mail->refresh()->status);
        Event::assertNotDispatched(SequenceStepSent::class);
    }

    public function test_ses_send_event_after_local_sent_write_keeps_status_and_does_not_dispatch_the_event_again(): void
    {
        Event::fake([SequenceStepSent::class]);
        $mail = ContactMail::factory()->forSequenceStep()->create([
            'status' => 'sent',
            'sent_at' => now()->subMinute(),
        ]);

        $this->process($mail->id, 'SEND');
        $this->process($mail->id, 'SEND');

        $this->assertSame('sent', $mail->refresh()->status);
        $this->assertNotNull($mail->sent_at);
        Event::assertNotDispatched(SequenceStepSent::class);
    }

    public function test_late_send_event_does_not_regress_a_delivered_sequence_mail(): void
    {
        Event::fake([SequenceStepSent::class]);
        $mail = ContactMail::factory()->forSequenceStep()->create([
            'status' => 'delivered',
            'sent_at' => now()->subMinute(),
            'delivered_at' => now(),
        ]);

        $this->process($mail->id, 'SEND');

        $this->assertSame('delivered', $mail->refresh()->status);
    }

    public function test_send_event_for_newsletter_does_not_dispatch(): void
    {
        Event::fake([SequenceStepSent::class]);
        $mail = ContactMail::factory()->forNewsletter()->create();

        $this->process($mail->id, 'SEND');

        Event::assertNotDispatched(SequenceStepSent::class);
    }

    public function test_delivery_event_for_sequence_step_does_not_dispatch(): void
    {
        Event::fake([SequenceStepSent::class]);
        $mail = ContactMail::factory()->forSequenceStep()->create();

        $this->process($mail->id, 'DELIVERY');

        Event::assertNotDispatched(SequenceStepSent::class);
    }
}
