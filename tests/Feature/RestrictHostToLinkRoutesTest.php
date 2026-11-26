<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestrictHostToLinkRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function makeLinkDomain(string $host = 'mail.example.com', bool $verified = true): void
    {
        EmailIdentity::factory()->withLinkDomain($host, verified: $verified)->create();
    }

    private function makeContactMail(): ContactMail
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        return ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
        ]);
    }

    public function test_link_route_works_on_verified_link_domain(): void
    {
        $this->makeLinkDomain();
        $contactMail = $this->makeContactMail();

        $this->get("http://mail.example.com/unsubscribe/{$contactMail->uuid}")
            ->assertOk();
    }

    /**
     * The List-Unsubscribe header points at the customer's link domain, so the
     * provider's POST arrives on that host with no CSRF token and no cookies.
     * A 404, a 419, or a redirect here makes every one-click unsubscribe fail.
     */
    public function test_one_click_unsubscribe_post_unsubscribes_the_contact_on_the_link_domain(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com')->create();
        $message = Message::factory()
            ->for(Newsletter::factory(), 'messageable')
            ->create(['email_identity_id' => $identity->id]);
        $contactMail = ContactMail::factory()->create(['message_id' => $message->id]);

        $link = $contactMail->one_click_unsubscribe_link;

        $this->assertSame("https://mail.example.com/one-click-unsubscribe/{$contactMail->uuid}", $link);

        $this->withMiddleware()
            ->post($link, ['List-Unsubscribe' => 'One-Click'])
            ->assertOk();

        $this->assertNotNull(Contact::withoutGlobalScopes()->find($contactMail->contact_id)->unsubscribed_at);
        $this->assertNotNull(ContactMail::withoutGlobalScopes()->find($contactMail->id)->unsubscribed_at);
    }

    public function test_dashboard_blocked_on_link_domain(): void
    {
        $this->makeLinkDomain();

        $this->get('http://mail.example.com/dashboard')->assertNotFound();
    }

    public function test_login_blocked_on_link_domain(): void
    {
        $this->makeLinkDomain();

        $this->get('http://mail.example.com/login')->assertNotFound();
    }

    public function test_health_check_works_on_link_domain(): void
    {
        $this->makeLinkDomain();

        $this->get('http://mail.example.com/up')->assertOk();
    }

    public function test_health_check_works_on_localhost_when_the_app_url_is_public(): void
    {
        config(['app.url' => 'https://mail.byomailer.example']);

        $this->get('http://localhost/up')->assertOk();
    }

    public function test_unknown_host_blocked_entirely(): void
    {
        $contactMail = $this->makeContactMail();

        $this->get("http://random-host.com/unsubscribe/{$contactMail->uuid}")
            ->assertNotFound();
        $this->get('http://random-host.com/dashboard')->assertNotFound();
    }

    public function test_unverified_link_domain_blocked(): void
    {
        $this->makeLinkDomain(verified: false);
        $contactMail = $this->makeContactMail();

        $this->get("http://mail.example.com/unsubscribe/{$contactMail->uuid}")
            ->assertNotFound();
    }

    /**
     * `LinkDomainProbe` calls `/up` on a new link domain before activation.
     */
    public function test_health_check_works_on_unverified_link_domain(): void
    {
        $this->makeLinkDomain(verified: false);

        $this->get('http://mail.example.com/up')->assertOk();
        $this->get('http://mail.example.com/dashboard')->assertNotFound();
    }

    public function test_health_check_works_on_unknown_host_but_other_paths_stay_blocked(): void
    {
        $this->get('http://random-host.com/up')->assertOk();
        $this->get('http://random-host.com/login')->assertNotFound();
    }

    public function test_link_route_still_works_on_canonical_host(): void
    {
        $contactMail = $this->makeContactMail();

        $this->get("/unsubscribe/{$contactMail->uuid}")->assertOk();
    }

    public function test_dashboard_not_blocked_on_canonical_host(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_signed_preferences_link_validates_on_custom_link_domain(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com')->create();
        $message = Message::factory()
            ->for(Newsletter::factory(), 'messageable')
            ->create(['email_identity_id' => $identity->id]);
        $contactMail = ContactMail::factory()->create(['message_id' => $message->id]);

        $link = $contactMail->preferences_link;

        $this->assertStringStartsWith('https://mail.example.com/preferences/', $link);

        $this->get($link)->assertOk();
    }

    public function test_preferences_link_without_signature_is_rejected_on_link_domain(): void
    {
        $this->makeLinkDomain();
        $contactMail = $this->makeContactMail();

        $this->get("https://mail.example.com/preferences/{$contactMail->uuid}")
            ->assertForbidden();
    }
}
