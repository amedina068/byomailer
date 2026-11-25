<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class LinkClickTrackingTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    public function test_a_click_stores_the_link_name_user_agent_and_ip_address(): void
    {
        $mail = $this->makeMail(User::factory()->create());

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

        $click = ContactMailClick::query()->sole();
        $this->assertSame('https://example.com/sale?utm_source=news', $click->link);
        $this->assertSame('black-friday', $click->link_name);
        $this->assertSame('Mozilla/5.0 (Test)', $click->user_agent);
        $this->assertSame('203.0.113.7', $click->ip_address);
    }

    public function test_a_click_on_an_unnamed_link_stores_no_link_name(): void
    {
        $mail = $this->makeMail(User::factory()->create());

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, null)]));

        $this->assertNull(ContactMailClick::query()->sole()->link_name);
    }

    public function test_a_link_name_from_ses_is_normalized_and_long_values_are_cut(): void
    {
        $mail = $this->makeMail(User::factory()->create());
        $event = $this->clickEvent($mail, 'Black Friday');
        $event['click']['userAgent'] = str_repeat('a', 600);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$event]));

        $click = ContactMailClick::query()->sole();
        $this->assertSame('black-friday', $click->link_name);
        $this->assertSame(512, strlen((string) $click->user_agent));
    }

    protected function makeMail(User $user): ContactMail
    {
        return ContactMail::factory()
            ->for(Contact::factory()->for($user))
            ->for(Newsletter::factory()->for($user), 'mailable')
            ->create(['status' => 'delivered']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function clickEvent(ContactMail $mail, ?string $linkName): array
    {
        return [
            'eventType' => 'Click',
            'mail' => ['tags' => ['contact_mail_id' => [(string) $mail->id]]],
            'click' => array_filter([
                'timestamp' => now()->toISOString(),
                'link' => 'https://example.com/sale?utm_source=news',
                'linkTags' => $linkName === null ? null : ['bm_link' => [$linkName]],
                'userAgent' => 'Mozilla/5.0 (Test)',
                'ipAddress' => '203.0.113.7',
            ]),
        ];
    }
}
