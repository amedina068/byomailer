<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the rendered email body against placeholder mangling. UTM injection
 * round-trips html through DOMDocument, which URL-encodes `href`/`src` values —
 * every template variable in the full body (template shell + content slot)
 * must still resolve to a real value, not arrive as broken literal text.
 */
class NewsletterVariableRenderingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $templateData
     */
    private function renderBody(string $bodyHtml, array $templateData = ['name' => 'Ada', 'company' => 'Analytical Engines']): string
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'draft', 'filters' => []]);

        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'email_identity_id' => $identity->id,
            'subject' => 'Hi {{ name }}',
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => $bodyHtml,
            'text_content' => 'Unsubscribe: {{ unsubscribe_url }}',
        ]);

        $contact = Contact::factory()->for($user)->create(['email' => 'ada@example.com', 'name' => 'Ada']);

        $contactMail = ContactMail::create([
            'contact_id' => $contact->id,
            'message_id' => $message->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'subject' => 'Hi {{ name }}',
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'template_data' => $templateData,
            'status' => 'pending',
        ]);

        return (new NewsletterMail($contactMail))->render();
    }

    public function test_full_body_resolves_every_variable_to_a_real_value(): void
    {
        // Template shell (header + footer) wrapping a content slot — variables
        // live in body text, an href, and the footer links.
        $body = <<<'HTML'
        <header><p>{{ company }} newsletter</p></header>
        <div data-slot="content">
          <p>Hi {{ name }}, here is the latest.</p>
          <p><a href="https://blog.test/welcome">Read more</a></p>
        </div>
        <footer>
          <a href="{{ unsubscribe_url }}">Unsubscribe</a> ·
          <a href="{{ preferences_url }}">Manage preferences</a>
        </footer>
        HTML;

        $rendered = $this->renderBody($body);

        // Every variable resolved to its real value.
        $this->assertStringContainsString('Hi Ada, here is the latest.', $rendered);
        $this->assertStringContainsString('Analytical Engines newsletter', $rendered);
        $this->assertStringContainsString('href="'.url('/unsubscribe/'), $rendered);
        $this->assertStringContainsString('preferences/', $rendered);

        // No placeholder, mangled or otherwise, survives into the delivered body.
        $this->assertStringNotContainsString('{{', $rendered);
        $this->assertStringNotContainsString('%7B', $rendered);
        $this->assertStringNotContainsString('%20unsubscribe_url%20', $rendered);
        $this->assertStringNotContainsString('%20preferences_url%20', $rendered);
    }

    public function test_unsubscribe_link_in_footer_href_is_clickable_after_utm_injection(): void
    {
        $body = '<div data-slot="content"><p>Hello</p></div>'
            .'<footer><a href="{{ unsubscribe_url }}">Unsubscribe</a></footer>';

        $rendered = $this->renderBody($body);

        // The href resolves to the real unsubscribe URL and carries no UTM params.
        $this->assertMatchesRegularExpression(
            '#href="'.preg_quote(url('/unsubscribe'), '#').'/[a-f0-9-]+"#',
            $rendered,
        );
        $this->assertStringNotContainsString('unsubscribe_url', $rendered);
        $this->assertStringNotContainsString('utm_medium', $rendered);
    }

    public function test_variable_link_gets_utm_parameters_after_the_value_is_resolved(): void
    {
        $rendered = $this->renderBody(
            '<p><a href="{{ checkout_url }}">Buy</a></p><footer><a href="{{ unsubscribe_url }}">Unsubscribe</a> <a href="{{ preferences_url }}">Preferences</a></footer>',
            ['checkout_url' => 'https://shop.test/checkout/abc'],
        );

        $this->assertSame([
            'utm_medium' => 'mail',
            'utm_source' => 'hi-name',
            'utm_campaign' => 'newsletter',
        ], $this->queryOfLink($rendered, 'shop.test/checkout/abc'));
        $this->assertSame([], $this->queryOfLink($rendered, '/unsubscribe/'));
        $this->assertArrayNotHasKey('utm_medium', $this->queryOfLink($rendered, '/preferences/'));
    }

    public function test_variable_link_with_a_query_suffix_is_one_valid_url_with_utm_parameters(): void
    {
        $rendered = $this->renderBody(
            '<p><a href="{{ checkout_url }}?coupon=LVFOREVER">Buy</a></p>',
            ['checkout_url' => 'https://shop.test/checkout?token=abc'],
        );

        $href = $this->hrefOf($rendered, 'shop.test/checkout');

        $this->assertSame(1, substr_count($href, '?'));
        $this->assertSame([
            'utm_medium' => 'mail',
            'utm_source' => 'hi-name',
            'utm_campaign' => 'newsletter',
            'token' => 'abc',
            'coupon' => 'LVFOREVER',
        ], $this->queryOfLink($rendered, 'shop.test/checkout'));
    }

    public function test_utm_source_in_the_variable_value_wins(): void
    {
        $rendered = $this->renderBody(
            '<p><a href="{{ checkout_url }}">Buy</a></p>',
            ['checkout_url' => 'https://shop.test/checkout?utm_source=partner'],
        );

        $query = $this->queryOfLink($rendered, 'shop.test/checkout');

        $this->assertSame('partner', $query['utm_source']);
        $this->assertSame('mail', $query['utm_medium']);
        $this->assertSame('newsletter', $query['utm_campaign']);
    }

    public function test_signed_url_in_a_variable_value_is_left_unchanged(): void
    {
        $signedUrl = 'https://shop.test/offer/7?expires=1700000000&signature=abc123';

        $rendered = $this->renderBody('<p><a href="{{ offer_url }}">Offer</a></p>', ['offer_url' => $signedUrl]);

        $this->assertSame($signedUrl, $this->hrefOf($rendered, 'shop.test/offer'));
    }

    public function test_sequence_step_variable_link_gets_the_sequence_utm_parameters(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $sequence = Sequence::factory()->for($user)->create(['name' => 'Yearly to lifetime']);
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message->update([
            'subject' => 'Lifetime offer',
            'html_content' => '<p><a href="{{ checkout_url }}?coupon=LVFOREVER">Switch</a></p>',
        ]);
        $contact = Contact::factory()->for($user)->create();

        $contactMail = ContactMail::create([
            'contact_id' => $contact->id,
            'message_id' => $step->message->id,
            'mailable_type' => SequenceStep::class,
            'mailable_id' => $step->id,
            'subject' => 'Lifetime offer',
            'from_email' => $step->message->from_email,
            'from_name' => $step->message->from_name,
            'template_data' => ['checkout_url' => 'https://shop.test/checkout/abc'],
            'status' => 'pending',
        ]);

        $rendered = (new NewsletterMail($contactMail))->render();

        $this->assertSame([
            'utm_medium' => 'mail',
            'utm_source' => 'lifetime-offer',
            'utm_campaign' => 'yearly-to-lifetime',
            'coupon' => 'LVFOREVER',
        ], $this->queryOfLink($rendered, 'shop.test/checkout/abc'));
    }

    private function hrefOf(string $rendered, string $needle): string
    {
        preg_match_all('/href="([^"]*)"/', $rendered, $matches);

        foreach ($matches[1] as $href) {
            if (str_contains($href, $needle)) {
                return html_entity_decode($href);
            }
        }

        $this->fail("No link contains {$needle}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOfLink(string $rendered, string $needle): array
    {
        parse_str((string) parse_url($this->hrefOf($rendered, $needle), PHP_URL_QUERY), $query);

        return $query;
    }
}
