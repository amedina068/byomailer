<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The factories build records the way production does: the records of one
 * mail belong to one client, and a teammate shares the client of a user.
 */
class TenantFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teammate_shares_the_client_and_is_not_the_owner(): void
    {
        $owner = User::factory()->owner()->create();

        $teammate = User::factory()->teammateOf($owner)->create();

        $this->assertSame($owner->client_id, $teammate->client_id);
        $this->assertNotSame($owner->id, $teammate->id);
        $this->assertFalse($teammate->isOwner());
        $this->assertSame(UserRole::Editor, $teammate->role);
    }

    public function test_a_default_contact_mail_keeps_its_contact_and_newsletter_in_one_client(): void
    {
        $contactMail = ContactMail::factory()->create();

        $contact = Contact::withoutGlobalScopes()->findOrFail($contactMail->contact_id);
        $newsletter = Newsletter::withoutGlobalScopes()->findOrFail($contactMail->mailable_id);

        $this->assertSame(Newsletter::class, $contactMail->mailable_type);
        $this->assertSame($contact->client_id, $contactMail->client_id);
        $this->assertSame($contact->client_id, $newsletter->client_id);
    }

    public function test_a_default_contact_mail_points_at_the_message_of_its_newsletter(): void
    {
        $contactMail = ContactMail::factory()->create();
        $this->actingAsTenant($contactMail->client_id);

        $newsletter = Newsletter::withoutGlobalScopes()->findOrFail($contactMail->mailable_id);

        $this->assertNotNull($contactMail->message_id);
        $this->assertSame($newsletter->message->id, $contactMail->message_id);
    }

    public function test_a_contact_mail_for_a_given_contact_uses_a_newsletter_of_that_client(): void
    {
        $contact = Contact::factory()->create();

        $contactMail = ContactMail::factory()->for($contact)->forNewsletter()->create();

        $newsletter = Newsletter::withoutGlobalScopes()->findOrFail($contactMail->mailable_id);
        $this->assertSame($contact->client_id, $newsletter->client_id);
        $this->assertSame($contact->client_id, $contactMail->client_id);
    }

    public function test_a_sequence_step_contact_mail_keeps_its_step_in_the_client_of_its_contact(): void
    {
        $contact = Contact::factory()->create();

        $contactMail = ContactMail::factory()->for($contact)->forSequenceStep()->create();
        $this->actingAsTenant($contact->client_id);

        $step = SequenceStep::query()->with('sequence', 'message')->findOrFail($contactMail->mailable_id);
        $this->assertSame(SequenceStep::class, $contactMail->mailable_type);
        $this->assertSame($contact->client_id, $step->sequence->client_id);
        $this->assertSame($step->message->id, $contactMail->message_id);
    }

    public function test_a_click_points_at_the_contact_of_its_mail(): void
    {
        $click = ContactMailClick::factory()->create();

        $contactMail = ContactMail::withoutGlobalScopes()->findOrFail($click->contact_mail_id);

        $this->assertSame($contactMail->contact_id, $click->contact_id);
    }
}
