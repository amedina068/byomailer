<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ResubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_can_resubscribe_after_unsubscribing_from_newsletter(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->unsubscribed()
            ->create();

        $response = $this->post('/resubscribe/'.$contactMail->uuid);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Resubscribed')
        );

        $contact->refresh();
        $contactMail->refresh();

        $this->assertNull($contact->unsubscribed_at);
        $this->assertFalse($contact->unsubscribed_by_contact);
        $this->assertNull($contactMail->unsubscribed_at);
    }

    public function test_contact_can_resubscribe_after_unsubscribing_from_sequence_step(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        $sequence = Sequence::factory()->for($user)->create();
        $sequenceStep = SequenceStep::factory()->for($sequence)->create();

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->forSequenceStep($sequenceStep)
            ->unsubscribed()
            ->create();

        $response = $this->post('/resubscribe/'.$contactMail->uuid);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Resubscribed')
        );

        $contact->refresh();
        $contactMail->refresh();

        $this->assertNull($contact->unsubscribed_at);
        $this->assertFalse($contact->unsubscribed_by_contact);
        $this->assertNull($contactMail->unsubscribed_at);
    }

    public function test_resubscribe_returns_404_for_invalid_uuid(): void
    {
        $this->post('/resubscribe/invalid-uuid')->assertNotFound();
        $this->get('/resubscribe/invalid-uuid')->assertNotFound();
    }

    public function test_resubscribe_returns_404_when_user_ids_do_not_match(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        $newsletter = Newsletter::factory()->for($otherUser)->create();

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->unsubscribed()
            ->create();

        $response = $this->post('/resubscribe/'.$contactMail->uuid);

        $response->assertNotFound();
    }

    public function test_unsubscribe_page_renders_inertia_component(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create(['status' => 'sent']);

        $response = $this->get('/unsubscribe/'.$contactMail->uuid);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Unsubscribe')
            ->has('contact')
            ->where('contactMailUuid', $contactMail->uuid)
        );
    }

    /**
     * A GET carries no CSRF protection, so it must not put an opted-out
     * contact back on the list. A prefetcher or an `<img src>` with a leaked
     * link would otherwise undo the contact's own decision.
     */
    public function test_a_get_does_not_resubscribe_the_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->unsubscribed()
            ->create();

        $this->get('/resubscribe/'.$contactMail->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resubscribe')
                ->where('alreadySubscribed', false)
            );

        $contact->refresh();
        $contactMail->refresh();

        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertTrue($contact->unsubscribed_by_contact);
        $this->assertNotNull($contactMail->unsubscribed_at);
    }

    public function test_the_unsubscribe_page_offers_a_way_back_to_a_contact_who_already_unsubscribed(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->unsubscribed()
            ->create();

        $this->get('/unsubscribe/'.$contactMail->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Unsubscribe')
                ->where('alreadyUnsubscribed', true)
                ->where('contactMailUuid', $contactMail->uuid)
            );

        // The page posts to this route, so the way back has to work.
        $this->post('/resubscribe/'.$contactMail->uuid)->assertOk();

        $contact->refresh();

        $this->assertNull($contact->unsubscribed_at);
    }
}
