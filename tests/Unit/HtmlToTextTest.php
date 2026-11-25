<?php

namespace Tests\Unit;

use App\Support\HtmlToText;
use PHPUnit\Framework\TestCase;

class HtmlToTextTest extends TestCase
{
    public function test_it_keeps_link_urls(): void
    {
        $text = HtmlToText::convert('<p>Read the <a href="https://example.com/post">post</a>.</p>');

        $this->assertStringContainsString('https://example.com/post', $text);
    }

    public function test_it_keeps_paragraph_breaks(): void
    {
        $text = HtmlToText::convert('<p>First</p><p>Second</p>');

        $this->assertStringContainsString('First', $text);
        $this->assertStringContainsString('Second', $text);
        $this->assertStringContainsString("\n", $text);
    }

    public function test_it_returns_an_empty_string_for_empty_input(): void
    {
        $this->assertSame('', HtmlToText::convert(''));
        $this->assertSame('', HtmlToText::convert('   '));
        $this->assertSame('', HtmlToText::convert(null));
    }

    public function test_it_survives_malformed_markup(): void
    {
        $text = HtmlToText::convert('<p>Unclosed <b>bold<p>and another');

        $this->assertStringContainsString('Unclosed', $text);
    }

    public function test_it_leaves_template_placeholders_intact(): void
    {
        $text = HtmlToText::convert('<p>Hello {{ name }}</p>');

        $this->assertStringContainsString('{{ name }}', $text);
    }
}
