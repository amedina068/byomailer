<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailFooterRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_mail_strips_content_slot_wrapper(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'draft', 'filters' => []]);

        $bodyHtml = '<header>H</header><div data-slot="content"><p>Hello friend</p></div><footer>F</footer>';
        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'email_identity_id' => $identity->id,
            'subject' => 'Hi',
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => $bodyHtml,
            'text_content' => 'Hello friend',
        ]);

        $contact = Contact::factory()->for($user)->create(['email' => 'x@example.com', 'name' => 'X']);

        $contactMail = ContactMail::create([
            'contact_id' => $contact->id,
            'message_id' => $message->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'subject' => 'Hi',
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'template_data' => [],
            'status' => 'pending',
        ]);

        $rendered = (new NewsletterMail($contactMail))->render();

        $this->assertStringContainsString('<header>H</header>', $rendered);
        $this->assertStringContainsString('<p>Hello friend</p>', $rendered);
        $this->assertStringContainsString('<footer>F</footer>', $rendered);
        $this->assertStringNotContainsString('data-slot="content"', $rendered);
    }

    public function test_newsletter_mail_does_not_append_unsubscribe_footer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'draft', 'filters' => []]);

        $bodyHtml = '<p>Hello friend</p>';
        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'email_identity_id' => $identity->id,
            'subject' => 'Hi',
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => $bodyHtml,
            'text_content' => 'Hello friend',
        ]);

        $contact = Contact::factory()->for($user)->create(['email' => 'x@example.com', 'name' => 'X']);

        $contactMail = ContactMail::create([
            'contact_id' => $contact->id,
            'message_id' => $message->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'subject' => 'Hi',
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'template_data' => [],
            'status' => 'pending',
        ]);

        $rendered = (new NewsletterMail($contactMail))->render();

        $this->assertStringNotContainsString("Don't want these emails", $rendered);
        $this->assertStringNotContainsString('margin-top:2rem', $rendered);
        $this->assertStringContainsString('Hello friend', $rendered);
    }
}
