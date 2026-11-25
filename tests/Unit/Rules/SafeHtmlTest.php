<?php

namespace Tests\Unit\Rules;

use App\Rules\SafeHtml;
use PHPUnit\Framework\TestCase;

class SafeHtmlTest extends TestCase
{
    private function validate(string $html): ?string
    {
        $rule = new SafeHtml;
        $error = null;

        $rule->validate('html_content', $html, function (string $message) use (&$error): void {
            $error = $message;
        });

        return $error;
    }

    public function test_inline_style_with_font_family_is_allowed(): void
    {
        $html = '<p style="font-family: Arial, Helvetica, sans-serif">hello</p>';

        $this->assertNull($this->validate($html));
    }

    public function test_inline_style_with_font_size_is_allowed(): void
    {
        $html = '<p><span style="font-size: 24px">big</span></p>';

        $this->assertNull($this->validate($html));
    }

    public function test_inline_style_with_color_is_allowed(): void
    {
        $html = '<p><span style="color: #c8ff00">accent</span></p>';

        $this->assertNull($this->validate($html));
    }

    public function test_combined_typography_inline_style_is_allowed(): void
    {
        $html = '<h1 style="font-family: Georgia, serif; font-size: 32px; color: #111827">Heading</h1>';

        $this->assertNull($this->validate($html));
    }

    public function test_script_tag_is_blocked_even_in_styled_content(): void
    {
        $html = '<p style="color:#000"><script>alert(1)</script></p>';

        $this->assertNotNull($this->validate($html));
    }

    public function test_javascript_url_in_style_is_blocked(): void
    {
        $html = '<p style="background: url(javascript:alert(1))">x</p>';

        $this->assertNotNull($this->validate($html));
    }

    public function test_expression_in_style_is_blocked(): void
    {
        $html = '<p style="width: expression(alert(1))">x</p>';

        $this->assertNotNull($this->validate($html));
    }

    public function test_data_url_with_application_type_is_blocked(): void
    {
        $html = '<p><a href="data:application/xhtml+xml;base64,PHNjcmlwdD4=">x</a></p>';

        $this->assertSame('The :attribute contains unsafe HTML content.', $this->validate($html));
    }

    public function test_data_url_with_audio_type_is_blocked(): void
    {
        $html = '<p><a href="data:audio/x-wav;base64,AAAA">x</a></p>';

        $this->assertSame('The :attribute contains unsafe HTML content.', $this->validate($html));
    }

    public function test_data_url_with_html_type_is_blocked(): void
    {
        $html = '<p><a href="data:text/html;base64,PHNjcmlwdD4=">x</a></p>';

        $this->assertSame('The :attribute contains unsafe HTML content.', $this->validate($html));
    }

    public function test_data_url_with_svg_image_type_is_blocked(): void
    {
        $html = '<p><a href="data:image/svg+xml;base64,PHN2Zz4=">x</a></p>';

        $this->assertSame('The :attribute contains unsafe HTML content.', $this->validate($html));
    }

    public function test_data_url_with_raster_image_type_is_allowed(): void
    {
        foreach (['png', 'jpeg', 'jpg', 'gif', 'webp'] as $type) {
            $html = '<p><img src="data:image/'.$type.';base64,iVBORw0KGgo=" alt=""></p>';

            $this->assertNull($this->validate($html), 'data:image/'.$type.' must pass');
        }
    }
}
