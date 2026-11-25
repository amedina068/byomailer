<?php

namespace Tests\Unit;

use App\Concerns\AppliesUtmParameters;
use PHPUnit\Framework\TestCase;

class AppliesUtmParametersTest extends TestCase
{
    use AppliesUtmParameters;

    /**
     * @param  array<string, string>  $utm
     */
    private function utm(string $html, array $utm = ['utm_medium' => 'mail', 'utm_source' => 'x']): string
    {
        return self::addUtmParametersToHtml($html, $utm);
    }

    public function test_spaced_placeholder_in_href_keeps_its_spaces_and_is_not_encoded(): void
    {
        $result = $this->utm('<a href="{{ unsubscribe_url }}">Unsubscribe</a>');

        // Spaces stay — the placeholder is left exactly as authored.
        $this->assertStringContainsString('href="{{ unsubscribe_url }}"', $result);
        // DOMDocument's %20 encoding must not survive.
        $this->assertStringNotContainsString('%20', $result);
    }

    public function test_unsubscribe_and_preferences_placeholders_both_survive_un_encoded(): void
    {
        $result = $this->utm(
            '<a href="{{ unsubscribe_url }}">u</a> <a href="{{ preferences_url }}">p</a>',
        );

        $this->assertStringContainsString('href="{{ unsubscribe_url }}"', $result);
        $this->assertStringContainsString('href="{{ preferences_url }}"', $result);
        $this->assertStringNotContainsString('%20', $result);
    }

    public function test_any_variable_name_inside_an_href_is_un_encoded_not_just_unsubscribe(): void
    {
        $result = $this->utm('<a href="{{ some_random_link }}">x</a>');

        $this->assertStringContainsString('href="{{ some_random_link }}"', $result);
        $this->assertStringNotContainsString('%20', $result);
    }

    public function test_placeholder_embedded_in_a_url_path_keeps_its_spaces(): void
    {
        $result = $this->utm('<a href="https://app.test/go/{{ token }}">x</a>');

        $this->assertStringContainsString('{{ token }}', $result);
        $this->assertStringNotContainsString('%20token%20', $result);
    }

    public function test_real_links_still_receive_utm_parameters(): void
    {
        $result = $this->utm('<a href="https://example.com/post">read</a>');

        $this->assertStringContainsString('utm_medium=mail', $result);
    }

    public function test_placeholder_hrefs_never_receive_utm_parameters(): void
    {
        $result = $this->utm('<a href="{{ unsubscribe_url }}">u</a>');

        $this->assertStringNotContainsString('utm_medium', $result);
    }

    public function test_already_no_space_placeholder_is_left_alone(): void
    {
        $result = $this->utm('<a href="{{unsubscribe_url}}">u</a>');

        $this->assertStringContainsString('href="{{unsubscribe_url}}"', $result);
        $this->assertStringNotContainsString('%20', $result);
    }

    public function test_fully_encoded_placeholder_braces_are_restored(): void
    {
        // Some libxml versions encode the braces too, not just the spaces.
        $result = $this->utm('<a href="%7B%7B%20unsubscribe_url%20%7D%7D">u</a>');

        $this->assertStringContainsString('href="{{ unsubscribe_url }}"', $result);
        $this->assertStringNotContainsString('%7B', $result);
        $this->assertStringNotContainsString('%7D', $result);
        $this->assertStringNotContainsString('%20', $result);
    }

    public function test_placeholder_inside_an_img_src_is_un_encoded(): void
    {
        $result = $this->utm('<img src="{{ logo_url }}" alt="logo">');

        $this->assertStringContainsString('src="{{ logo_url }}"', $result);
        $this->assertStringNotContainsString('%20', $result);
    }

    public function test_full_email_body_keeps_every_variable_intact_across_text_href_and_src(): void
    {
        // A realistic full body: header logo (src var), greeting (text var),
        // an authored link with an embedded var, a real link, and the footer
        // unsubscribe / preferences links.
        $body = <<<'HTML'
        <header><img src="{{ logo_url }}" alt="logo"></header>
        <p>Hi {{ name }}, welcome.</p>
        <p><a href="https://blog.test/post/{{ slug }}">Read the post</a></p>
        <p><a href="https://example.com/real-link">A real link</a></p>
        <footer>
          <a href="{{ unsubscribe_url }}">Unsubscribe</a> ·
          <a href="{{ preferences_url }}">Preferences</a>
        </footer>
        HTML;

        $result = $this->utm($body);

        // Every variable survives exactly as authored, anywhere in the body.
        foreach (['{{ logo_url }}', '{{ name }}', '{{ slug }}', '{{ unsubscribe_url }}', '{{ preferences_url }}'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $result);
        }

        // Nothing mangled or encoded anywhere in the body.
        $this->assertStringNotContainsString('%20', $result);
        $this->assertStringNotContainsString('%7B', $result);
        $this->assertStringNotContainsString('%7D', $result);

        // Only the real link receives UTM params.
        $this->assertStringContainsString('real-link?utm_medium=mail', $result);
        $this->assertStringNotContainsString('{{ unsubscribe_url }}?utm', $result);
        $this->assertStringNotContainsString('post/{{ slug }}?utm', $result);
    }

    public function test_signed_url_is_left_unchanged(): void
    {
        $result = $this->utm('<a href="https://app.test/offer?expires=1700000000&amp;signature=abc123">x</a>');

        $this->assertStringContainsString('href="https://app.test/offer?expires=1700000000&signature=abc123"', $result);
        $this->assertStringNotContainsString('utm_', $result);
    }

    public function test_one_click_unsubscribe_link_is_left_unchanged(): void
    {
        $result = $this->utm('<a href="https://mail.app.test/one-click-unsubscribe/abc">x</a>');

        $this->assertStringContainsString('href="https://mail.app.test/one-click-unsubscribe/abc"', $result);
        $this->assertStringNotContainsString('utm_', $result);
    }

    public function test_variable_link_points_at_the_utm_copy_of_the_value(): void
    {
        $result = self::pointVariableLinksAtUtmValues(
            '<a href="{{ checkout_url }}">a</a> <a href="{{ checkout_url }}?coupon=LVFOREVER#top">b</a>',
        );

        $this->assertStringContainsString('href="{{{ checkout_url__utm }}}"', $result);
        $this->assertStringContainsString('href="{{{ checkout_url__utm }}}{{{ checkout_url__utm__qs }}}coupon=LVFOREVER#top"', $result);
    }

    public function test_system_and_non_query_variable_links_are_not_pointed_at_a_utm_copy(): void
    {
        $content = '<a href="{{ unsubscribe_url }}">u</a> <a href="{{ preferences_url }}">p</a>'
            .' <a href="{{ base_url }}/path">b</a> <a href="{{ checkout_url }}?utm_source=custom">c</a>'
            .' <a href="https://app.test/{{ slug }}">s</a>';

        $this->assertSame($content, self::pointVariableLinksAtUtmValues($content));
    }

    public function test_utm_copy_of_a_value_follows_the_fixed_link_rules(): void
    {
        $data = self::withUtmValues([
            'checkout_url' => 'https://app.test/checkout?ref=a',
            'tagged_url' => 'https://app.test/checkout?utm_source=partner',
            'signed_url' => 'https://app.test/offer?expires=1&signature=abc',
            'unsubscribe_url' => 'https://app.test/unsubscribe/abc',
            'name' => 'Ada "Lovelace" <b>',
        ], ['utm_medium' => 'mail', 'utm_source' => 'x', 'utm_campaign' => 'newsletter']);

        $this->assertSame('https://app.test/checkout?utm_medium=mail&utm_source=x&utm_campaign=newsletter&ref=a', $data['checkout_url__utm']);
        $this->assertSame('https://app.test/checkout?utm_medium=mail&utm_source=partner&utm_campaign=newsletter', $data['tagged_url__utm']);
        $this->assertSame('https://app.test/offer?expires=1&signature=abc', $data['signed_url__utm']);
        $this->assertArrayNotHasKey('unsubscribe_url__utm', $data);
        $this->assertSame('Ada%20%22Lovelace%22%20%3Cb%3E', $data['name__utm']);
    }
}
