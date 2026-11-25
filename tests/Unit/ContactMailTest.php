<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Message;
use App\Models\Newsletter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactMailTest extends TestCase
{
    use RefreshDatabase;

    private function contactMailWithLinkDomain(?string $host, bool $verified = true): ContactMail
    {
        $attributes = [];

        if ($host !== null) {
            $identity = EmailIdentity::factory()->withLinkDomain($host, verified: $verified)->create();
            $message = Message::factory()
                ->for(Newsletter::factory(), 'messageable')
                ->create(['email_identity_id' => $identity->id]);
            $attributes['message_id'] = $message->id;
        }

        return ContactMail::factory()->create($attributes);
    }

    public function test_it_exposes_an_unsubscribe_link_attribute(): void
    {
        $contactMail = ContactMail::factory()->create();

        $link = $contactMail->unsubscribe_link;

        $this->assertNotNull($link);
        $this->assertStringContainsString("/unsubscribe/{$contactMail->uuid}", $link);
    }

    public function test_unsubscribe_link_uses_verified_link_domain(): void
    {
        $contactMail = $this->contactMailWithLinkDomain('mail.example.com');

        $this->assertSame(
            "https://mail.example.com/unsubscribe/{$contactMail->uuid}",
            $contactMail->unsubscribe_link
        );
    }

    public function test_unsubscribe_link_falls_back_to_app_host_without_link_domain(): void
    {
        $contactMail = $this->contactMailWithLinkDomain(null);

        $this->assertStringNotContainsString('mail.example.com', $contactMail->unsubscribe_link);
        $this->assertStringContainsString("/unsubscribe/{$contactMail->uuid}", $contactMail->unsubscribe_link);
    }

    public function test_unsubscribe_link_ignores_unverified_link_domain(): void
    {
        $contactMail = $this->contactMailWithLinkDomain('mail.example.com', verified: false);

        $this->assertStringNotContainsString('mail.example.com', $contactMail->unsubscribe_link);
    }

    public function test_opt_in_link_uses_verified_link_domain(): void
    {
        $contactMail = $this->contactMailWithLinkDomain('mail.example.com');

        $this->assertSame(
            "https://mail.example.com/opt-in/{$contactMail->uuid}",
            $contactMail->opt_in_link
        );
    }

    public function test_opt_in_link_falls_back_to_app_host_without_link_domain(): void
    {
        $contactMail = $this->contactMailWithLinkDomain(null);

        $this->assertSame(url("/opt-in/{$contactMail->uuid}"), $contactMail->opt_in_link);
    }

    public function test_preferences_link_uses_verified_link_domain(): void
    {
        $contactMail = $this->contactMailWithLinkDomain('mail.example.com');

        $link = $contactMail->preferences_link;

        $this->assertStringStartsWith('https://mail.example.com/preferences/', $link);
        $this->assertStringContainsString('signature=', $link);
    }

    public function test_preferences_link_falls_back_without_link_domain(): void
    {
        $contactMail = $this->contactMailWithLinkDomain(null);

        $this->assertStringNotContainsString('mail.example.com', $contactMail->preferences_link);
        $this->assertStringContainsString('signature=', $contactMail->preferences_link);
    }

    public function test_contact_mail_is_unique_per_contact_and_mailable(): void
    {
        $contact = Contact::factory()->create();
        $newsletter = Newsletter::factory()->create();

        ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
        ]);

        $this->expectException(QueryException::class);

        ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
        ]);
    }
}
