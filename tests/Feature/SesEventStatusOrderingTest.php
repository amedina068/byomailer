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

class SesEventStatusOrderingTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    public function test_late_send_after_delivery_does_not_regress_status(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['status' => 'delivered', 'delivered_at' => now()->subMinute()]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('SEND', $contactMail->id)]));

        $contactMail->refresh();
        $this->assertSame('delivered', $contactMail->status);
        $this->assertNotNull($contactMail->sent_at);
    }

    public function test_late_send_after_open_does_not_regress_status(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['status' => 'opened', 'opened_at' => now()->subMinute()]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('SEND', $contactMail->id)]));

        $contactMail->refresh();
        $this->assertSame('opened', $contactMail->status);
    }

    public function test_late_delivery_after_open_does_not_regress_status(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['status' => 'opened', 'opened_at' => now()->subMinute()]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('DELIVERY', $contactMail->id, [
            'delivery' => ['timestamp' => now()->toISOString()],
        ])]));

        $contactMail->refresh();
        $this->assertSame('opened', $contactMail->status);
        $this->assertNotNull($contactMail->delivered_at);
    }

    public function test_progress_event_after_bounce_does_not_regress_status(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['status' => 'bounced', 'bounced_at' => now()->subMinute()]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([
            $this->event('SEND', $contactMail->id),
            $this->event('DELIVERY', $contactMail->id, [
                'delivery' => ['timestamp' => now()->toISOString()],
            ]),
            $this->event('OPEN', $contactMail->id, [
                'open' => ['timestamp' => now()->toISOString()],
            ]),
        ]));

        $contactMail->refresh();
        $this->assertSame('bounced', $contactMail->status);
    }

    public function test_terminal_event_overrides_any_progress_status(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['status' => 'opened', 'opened_at' => now()->subMinute()]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('COMPLAINT', $contactMail->id, [
            'complaint' => ['timestamp' => now()->toISOString()],
        ])]));

        $contactMail->refresh();
        $this->assertSame('complained', $contactMail->status);
    }

    public function test_in_order_progress_still_advances(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $contactMail = $this->makeMail(['status' => 'pending']);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('SEND', $contactMail->id)]));
        $this->assertSame('sent', $contactMail->fresh()->status);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('DELIVERY', $contactMail->id, [
            'delivery' => ['timestamp' => now()->toISOString()],
        ])]));
        $this->assertSame('delivered', $contactMail->fresh()->status);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('OPEN', $contactMail->id, [
            'open' => ['timestamp' => now()->toISOString()],
        ])]));
        $this->assertSame('opened', $contactMail->fresh()->status);
    }

    public function test_click_implies_open_without_an_open_event(): void
    {
        $mail = $this->makeMail(['status' => 'sent', 'sent_at' => now(), 'opened_at' => null, 'clicked_at' => null]);
        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->event('CLICK', $mail->id, ['click' => ['timestamp' => now()->toISOString(), 'link' => 'https://example.com/checkout']])]));
        $mail->refresh();
        $this->assertNotNull($mail->clicked_at);
        $this->assertNotNull($mail->opened_at);
        $this->assertSame('opened', $mail->status);
        $this->assertDatabaseHas('contact_mail_clicks', ['contact_mail_id' => $mail->id, 'link' => 'https://example.com/checkout']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMail(array $overrides = []): ContactMail
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        return ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create($overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(string $type, int $contactMailId, array $overrides = []): array
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
