<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Mail\OptInMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterMailHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_mail_headers_sets_x_ses_configuration_set_from_message_identity(): void
    {
        $contactMail = $this->makeContactMail('cs-from-identity');

        $headers = (new NewsletterMail($contactMail))->headers();

        $this->assertArrayHasKey('X-SES-CONFIGURATION-SET', $headers->text);
        $this->assertSame('cs-from-identity', $headers->text['X-SES-CONFIGURATION-SET']);
    }

    public function test_newsletter_mail_headers_reads_configuration_set_after_relationships_load(): void
    {
        $contactMail = $this->makeContactMail('cs-lazy-load');

        // Strip eager-loaded relationships to prove `loadMessageRelationship()` hydrates them.
        $contactMail->setRelations([]);

        $headers = (new NewsletterMail($contactMail))->headers();

        $this->assertSame('cs-lazy-load', $headers->text['X-SES-CONFIGURATION-SET']);
    }

    public function test_opt_in_mail_headers_sets_x_ses_configuration_set_from_message_identity(): void
    {
        $contactMail = $this->makeContactMail('cs-opt-in');

        $headers = (new OptInMail($contactMail))->headers();

        $this->assertArrayHasKey('X-SES-CONFIGURATION-SET', $headers->text);
        $this->assertSame('cs-opt-in', $headers->text['X-SES-CONFIGURATION-SET']);
    }

    public function test_newsletter_mail_headers_include_one_click_list_unsubscribe(): void
    {
        $contactMail = $this->makeContactMail('cs-list-unsub');

        $headers = (new NewsletterMail($contactMail))->headers();

        $this->assertSame(
            '<'.$contactMail->one_click_unsubscribe_link.'>',
            $headers->text['List-Unsubscribe'],
        );
        $this->assertStringContainsString('/one-click-unsubscribe/', $headers->text['List-Unsubscribe']);
        $this->assertSame(
            'List-Unsubscribe=One-Click',
            $headers->text['List-Unsubscribe-Post'],
        );
    }

    public function test_list_unsubscribe_headers_omitted_when_unsubscribe_link_unavailable(): void
    {
        $contactMail = $this->makeContactMail('cs-no-uuid');
        $contactMail->forceFill(['uuid' => null]);

        $headers = (new NewsletterMail($contactMail))->headers();

        $this->assertArrayNotHasKey('List-Unsubscribe', $headers->text);
        $this->assertArrayNotHasKey('List-Unsubscribe-Post', $headers->text);
    }

    public function test_newsletter_envelope_sets_reply_to_from_identity(): void
    {
        $contactMail = $this->makeContactMail('cs-reply-to', 'support@example.com', 'Support Team');

        $replyTo = (new NewsletterMail($contactMail))->envelope()->replyTo;

        $this->assertCount(1, $replyTo);
        $this->assertSame('support@example.com', $replyTo[0]->address);
        $this->assertSame('Support Team', $replyTo[0]->name);
    }

    public function test_opt_in_envelope_sets_reply_to_without_name(): void
    {
        $contactMail = $this->makeContactMail('cs-reply-to-opt-in', 'support@example.com');

        $replyTo = (new OptInMail($contactMail))->envelope()->replyTo;

        $this->assertCount(1, $replyTo);
        $this->assertSame('support@example.com', $replyTo[0]->address);
        $this->assertNull($replyTo[0]->name);
    }

    public function test_envelope_omits_reply_to_when_identity_has_none(): void
    {
        $contactMail = $this->makeContactMail('cs-no-reply-to');

        $this->assertSame([], (new NewsletterMail($contactMail))->envelope()->replyTo);
    }

    private function makeContactMail(string $configurationSetName, ?string $replyToEmail = null, ?string $replyToName = null): ContactMail
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => $configurationSetName,
            'reply_to_email' => $replyToEmail,
            'reply_to_name' => $replyToName,
        ]);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'subject' => 'Hi',
            'html_content' => '<p>hi</p>',
        ]);

        $contact = Contact::factory()->for($user)->create();

        return ContactMail::create([
            'contact_id' => $contact->id,
            'message_id' => $newsletter->message->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'subject' => 'Hi',
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'template_data' => [],
            'status' => 'pending',
        ]);
    }
}
