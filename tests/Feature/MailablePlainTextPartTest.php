<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\Block;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `text/plain` part must be plain text. A verification run found raw `<p>`
 * tags in it, because the text was derived from the HTML before the blocks
 * were expanded, and the block HTML was then pasted into the text as well.
 */
class MailablePlainTextPartTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_block_reaches_the_plain_part_as_text_not_html(): void
    {
        $contactMail = $this->makeContactMail(
            '<p>Hello</p>{{ block.footer }}',
            textContent: null,
        );

        $text = $this->textPartOf($contactMail);

        $this->assertStringNotContainsString('<p>', $text);
        $this->assertStringNotContainsString('</p>', $text);
        $this->assertStringContainsString('Acme Inc, 1 Main St', $text);
    }

    public function test_a_stored_plain_body_also_gets_the_text_form_of_a_block(): void
    {
        $contactMail = $this->makeContactMail(
            '<p>Hello</p>{{ block.footer }}',
            textContent: "Hello\n\n{{ block.footer }}",
        );

        $text = $this->textPartOf($contactMail);

        $this->assertStringNotContainsString('<p>', $text);
        $this->assertStringContainsString('Acme Inc, 1 Main St', $text);
    }

    public function test_the_html_part_still_gets_the_block_html(): void
    {
        $contactMail = $this->makeContactMail(
            '<p>Hello</p>{{ block.footer }}',
            textContent: null,
        );

        $html = (string) (new NewsletterMail($contactMail))->content()->with['htmlContent'];

        $this->assertStringContainsString('<p>Acme Inc, 1 Main St</p>', $html);
    }

    public function test_a_block_in_the_subject_resolves_to_text(): void
    {
        $contactMail = $this->makeContactMail('<p>Hello</p>', textContent: null);
        $contactMail->forceFill(['subject' => 'News from {{ block.brand }}'])->save();

        Block::factory()->for($contactMail->contact->user)->create([
            'name' => 'brand',
            'html_content' => '<strong>Acme</strong>',
        ]);

        $subject = (new NewsletterMail($contactMail))->envelope()->subject;

        $this->assertSame('News from Acme', $subject);
    }

    private function textPartOf(ContactMail $contactMail): string
    {
        return (string) (new NewsletterMail($contactMail))->content()->with['textContent'];
    }

    private function makeContactMail(string $html, ?string $textContent): ContactMail
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'cs-plain-text',
        ]);

        Block::factory()->for($user)->create([
            'name' => 'footer',
            'html_content' => '<p>Acme Inc, 1 Main St</p>',
        ]);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'subject' => 'Plain text check',
            'html_content' => $html,
            'text_content' => $textContent,
        ]);

        $contact = Contact::factory()->for($user)->create();

        return ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'message_id' => $newsletter->message->id,
                'subject' => 'Plain text check',
                'template_data' => [],
            ]);
    }
}
