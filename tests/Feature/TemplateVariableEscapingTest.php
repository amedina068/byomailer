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

/**
 * Contact data comes from public forms, imports and the API. A value must
 * not add markup to the HTML part of an email. The subject and the text
 * part are plain text, so they keep the raw value.
 */
class TemplateVariableEscapingTest extends TestCase
{
    use RefreshDatabase;

    private const MARKUP_NAME = 'Ann <a href="https://evil.test">Reset</a> & Co';

    public function test_html_part_escapes_a_contact_value(): void
    {
        $contactMail = $this->makeContactMail(['name' => self::MARKUP_NAME]);

        $html = (string) (new NewsletterMail($contactMail))->content()->with['htmlContent'];

        $this->assertStringContainsString('<p>Hi Ann &lt;a href=', $html);
        $this->assertStringContainsString('&gt;Reset&lt;/a&gt; &amp; Co</p>', $html);
        $this->assertStringNotContainsString('<a href="https://evil.test">', $html);
    }

    public function test_text_part_and_subject_keep_the_raw_value(): void
    {
        $contactMail = $this->makeContactMail(['name' => self::MARKUP_NAME]);
        $mail = new NewsletterMail($contactMail);

        $this->assertStringContainsString('Hi '.self::MARKUP_NAME, (string) $mail->content()->with['textContent']);
        $this->assertSame('News for '.self::MARKUP_NAME, $mail->envelope()->subject);
    }

    public function test_link_in_an_href_still_works_after_escaping(): void
    {
        $contactMail = $this->makeContactMail(
            ['checkout_url' => 'https://shop.test/cart?a=1&b=2'],
            '<p><a href="{{ checkout_url }}?coupon=X">Buy</a></p>',
        );

        $html = (string) (new NewsletterMail($contactMail))->content()->with['htmlContent'];

        $this->assertMatchesRegularExpression('#href="https://shop\.test/cart\?[^"]*a=1&(amp;)?b=2&(amp;)?coupon=X"#', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * @param  array<string, string>  $templateData
     */
    private function makeContactMail(array $templateData, string $html = '<p>Hi {{ name }}</p>'): ContactMail
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'cs-escaping',
        ]);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'subject' => 'News for {{ name }}',
            'html_content' => $html,
            'text_content' => null,
        ]);

        return ContactMail::factory()
            ->for(Contact::factory()->for($user)->create())
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
                'message_id' => $newsletter->message->id,
                'subject' => 'News for {{ name }}',
                'template_data' => $templateData,
            ]);
    }
}
