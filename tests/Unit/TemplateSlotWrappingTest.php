<?php

namespace Tests\Unit;

use App\Support\ContentSlot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the regex contracts shared between the JS template-slot helpers
 * (resources/js/lib/template-slot.ts) and the server-side ContentSlot support
 * class used by send paths (BuildsFromContactMail, SesBulkEmailService, and
 * the backfill migration).
 *
 * If these regex shapes drift between JS and PHP, the round trip between
 * TipTap save and email send will break. Test both contracts here.
 */
class TemplateSlotWrappingTest extends TestCase
{
    public function test_hydrate_replaces_placeholder_with_slot_div(): void
    {
        $out = ContentSlot::hydrate('<p>{{ content }}</p>', '<p>Hello</p>');

        $this->assertSame('<p><div data-slot="content"><p>Hello</p></div></p>', $out);
    }

    public function test_hydrate_only_replaces_first_occurrence(): void
    {
        $out = ContentSlot::hydrate('{{ content }}<hr>{{ content }}', '<p>x</p>');

        $this->assertStringContainsString('<div data-slot="content">', $out);
        $this->assertStringContainsString('{{ content }}', $out);
    }

    public function test_hydrate_falls_back_to_empty_paragraph(): void
    {
        $out = ContentSlot::hydrate('<p>{{ content }}</p>', '');

        $this->assertSame('<p><div data-slot="content"><p></p></div></p>', $out);
    }

    public function test_extract_recovers_slot_content(): void
    {
        $composed = '<header>H</header><div data-slot="content"><p>Hello</p></div><footer>F</footer>';

        $this->assertSame('<p>Hello</p>', ContentSlot::extract($composed));
    }

    public function test_unwrap_flattens_slot_wrapper(): void
    {
        $composed = '<header>H</header><div data-slot="content"><p>Hello</p></div><footer>F</footer>';

        $this->assertSame(
            '<header>H</header><p>Hello</p><footer>F</footer>',
            ContentSlot::unwrap($composed),
        );
    }

    public function test_round_trip_preserves_slot_content(): void
    {
        $template = '<header>H</header><p>{{ content }}</p><footer>F</footer>';
        $original = '<p>Body <strong>bold</strong></p>';

        $composed = ContentSlot::hydrate($template, $original);
        $extracted = ContentSlot::extract($composed);

        $this->assertSame($original, $extracted);
    }

    public function test_extract_handles_missing_slot_gracefully(): void
    {
        $this->assertSame('', ContentSlot::extract('<p>no slot here</p>'));
    }

    public function test_has_wrapper_detects_slot_div(): void
    {
        $this->assertTrue(ContentSlot::hasWrapper('<div data-slot="content"><p>x</p></div>'));
        $this->assertFalse(ContentSlot::hasWrapper('<p>plain content</p>'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function replacementSyntaxProvider(): array
    {
        return [
            'price' => ['<p>A figure, $70 saved.</p>'],
            'price per year' => ['<p>Unlimited is <strong>$37/year</strong> or $97 once.</p>'],
            'backslash backreference' => ['<p>Literal \1 and \\\\1 text.</p>'],
            'braced backreference' => ['<p>Literal ${1} and $0 text.</p>'],
            'dollar ampersand' => ['<p>Literal $& and $$ text.</p>'],
        ];
    }

    #[DataProvider('replacementSyntaxProvider')]
    public function test_hydrate_keeps_replacement_syntax_in_slot_content_literal(string $slot): void
    {
        $out = ContentSlot::hydrate('<header>H</header><p>{{ content }}</p>', $slot);

        $this->assertSame('<header>H</header><p><div data-slot="content">'.$slot.'</div></p>', $out);
        $this->assertSame($slot, ContentSlot::extract($out));
    }

    #[DataProvider('replacementSyntaxProvider')]
    public function test_unwrap_keeps_replacement_syntax_in_slot_content_literal(string $slot): void
    {
        $composed = '<header>H</header><div data-slot="content">'.$slot.'</div><footer>F</footer>';

        $this->assertSame('<header>H</header>'.$slot.'<footer>F</footer>', ContentSlot::unwrap($composed));
    }
}
