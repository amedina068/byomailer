<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\TestMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * RFC 8058. Gmail, Yahoo and Apple Mail POST to the URL in `List-Unsubscribe`
 * with no cookies and no CSRF token, and they do not follow redirects.
 */
class OneClickUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_post_without_csrf_token_unsubscribes_the_contact(): void
    {
        [$contact, $contactMail] = $this->makeNewsletterMail();

        $response = $this->withMiddleware()
            ->post('/one-click-unsubscribe/'.$contactMail->uuid, [
                'List-Unsubscribe' => 'One-Click',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));

        $contact->refresh();
        $contactMail->refresh();

        $this->assertTrue($contact->unsubscribed_by_contact);
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertNotNull($contactMail->unsubscribed_at);
    }

    public function test_a_signed_in_user_of_another_client_can_unsubscribe_through_the_link(): void
    {
        [$contact, $contactMail] = $this->makeNewsletterMail();

        $this->actingAs(User::factory()->create())
            ->withMiddleware()
            ->post('/one-click-unsubscribe/'.$contactMail->uuid)
            ->assertOk();

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
    }

    public function test_the_route_is_exempt_from_csrf_verification(): void
    {
        [, $contactMail] = $this->makeNewsletterMail();

        $middleware = collect(Route::getRoutes()->getByName('contact-mails.one-click-unsubscribe')->gatherMiddleware());

        $this->assertTrue($middleware->contains('web'));

        // A 419 here is what the mailbox provider would see before the fix.
        $this->withMiddleware()
            ->post('/one-click-unsubscribe/'.$contactMail->uuid)
            ->assertStatus(200);
    }

    public function test_repeating_the_post_is_idempotent(): void
    {
        [$contact, $contactMail] = $this->makeNewsletterMail();

        $this->post('/one-click-unsubscribe/'.$contactMail->uuid)->assertOk();

        $contact->refresh();
        $firstUnsubscribedAt = $contact->unsubscribed_at;

        $this->post('/one-click-unsubscribe/'.$contactMail->uuid)->assertOk();

        $contact->refresh();

        $this->assertTrue($contact->unsubscribed_by_contact);
        $this->assertEquals($firstUnsubscribedAt, $contact->unsubscribed_at);
    }

    public function test_a_test_mail_never_unsubscribes_a_real_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $testMail = TestMail::factory()->for($newsletter, 'mailable')->create();

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($testMail, 'mailable')
            ->create(['status' => 'sent']);

        $this->post('/one-click-unsubscribe/'.$contactMail->uuid)->assertOk();

        $contact->refresh();

        $this->assertNull($contact->unsubscribed_at);
        $this->assertFalse((bool) $contact->unsubscribed_by_contact);
    }

    public function test_a_get_on_the_header_url_shows_the_confirmation_page_instead(): void
    {
        [$contact, $contactMail] = $this->makeNewsletterMail();

        $this->get('/one-click-unsubscribe/'.$contactMail->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Unsubscribe'));

        $contact->refresh();

        $this->assertNull($contact->unsubscribed_at);
    }

    public function test_a_contact_unsubscribes_from_the_newsletter_of_a_teammate(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        $contact = Contact::factory()->for($owner)->create(['unsubscribed_at' => null]);
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for(Newsletter::factory()->for($teammate)->create(), 'mailable')
            ->create(['status' => 'sent']);

        $this->post('/one-click-unsubscribe/'.$contactMail->uuid)->assertOk();

        $this->assertNotNull($contact->refresh()->unsubscribed_at);
    }

    public function test_a_contact_of_another_client_is_not_found(): void
    {
        $contact = Contact::factory()->create(['unsubscribed_at' => null]);
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for(Newsletter::factory()->create(), 'mailable')
            ->create(['status' => 'sent']);

        $this->post('/one-click-unsubscribe/'.$contactMail->uuid)->assertNotFound();

        $this->assertNull($contact->refresh()->unsubscribed_at);
    }

    public function test_a_contact_unsubscribes_from_an_opt_in_email(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for(MailList::factory()->for($user)->create(['opt_in_enabled' => true]), 'mailable')
            ->create(['status' => 'sent']);

        $this->post('/one-click-unsubscribe/'.$contactMail->uuid)->assertOk();

        $this->assertNotNull($contact->refresh()->unsubscribed_at);
        $this->assertTrue($contact->unsubscribed_by_contact);
    }

    public function test_an_opt_in_email_of_another_client_is_not_found(): void
    {
        $contact = Contact::factory()->create(['unsubscribed_at' => null]);
        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for(MailList::factory()->create(), 'mailable')
            ->create(['status' => 'sent']);

        $this->post('/one-click-unsubscribe/'.$contactMail->uuid)->assertNotFound();
    }

    public function test_an_unknown_uuid_is_not_found(): void
    {
        $this->post('/one-click-unsubscribe/does-not-exist')->assertNotFound();
    }

    /**
     * @return array{0: Contact, 1: ContactMail}
     */
    private function makeNewsletterMail(): array
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $newsletter = Newsletter::factory()->for($user)->create();

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);

        return [$contact, $contactMail];
    }
}
