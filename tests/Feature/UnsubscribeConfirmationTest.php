<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The unsubscribe page must not act on a GET. Corporate link scanners and mail
 * clients prefetch every URL in a message, so a mutating GET unsubscribed
 * contacts who never clicked anything.
 */
class UnsubscribeConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_get_does_not_unsubscribe_the_contact(): void
    {
        [$contact, $contactMail] = $this->makeNewsletterMail();

        $this->get('/unsubscribe/'.$contactMail->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Unsubscribe')
                ->where('alreadyUnsubscribed', false)
                ->where('contact.email', $contact->email)
            );

        $contact->refresh();
        $contactMail->refresh();

        $this->assertNull($contact->unsubscribed_at);
        $this->assertFalse((bool) $contact->unsubscribed_by_contact);
        $this->assertNull($contactMail->unsubscribed_at);
    }

    public function test_confirming_with_a_post_unsubscribes_the_contact(): void
    {
        [$contact, $contactMail] = $this->makeNewsletterMail();

        $this->post('/unsubscribe/'.$contactMail->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Unsubscribed'));

        $contact->refresh();
        $contactMail->refresh();

        $this->assertTrue($contact->unsubscribed_by_contact);
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertNotNull($contactMail->unsubscribed_at);
    }

    public function test_the_page_reports_a_contact_who_already_unsubscribed(): void
    {
        [$contact, $contactMail] = $this->makeNewsletterMail();

        Tenant::run($contact->client_id, fn () => $contact->forceFill([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ])->save());

        $this->get('/unsubscribe/'.$contactMail->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Unsubscribe')
                ->where('alreadyUnsubscribed', true)
            );
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
