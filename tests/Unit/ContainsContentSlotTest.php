<?php

namespace Tests\Unit;

use App\Rules\ContainsContentSlot;
use PHPUnit\Framework\TestCase;

class ContainsContentSlotTest extends TestCase
{
    private function validate(mixed $value): ?string
    {
        $rule = new ContainsContentSlot;
        $error = null;

        $rule->validate('html_content', $value, function (string $message) use (&$error): void {
            $error = $message;
        });

        return $error;
    }

    public function test_accepts_single_placeholder(): void
    {
        $this->assertNull($this->validate('<p>{{ content }}</p>'));
    }

    public function test_accepts_placeholder_with_varied_whitespace(): void
    {
        $this->assertNull($this->validate('<p>{{content}}</p>'));
        $this->assertNull($this->validate('<p>{{  content  }}</p>'));
    }

    public function test_rejects_missing_placeholder(): void
    {
        $error = $this->validate('<p>hello</p>');

        $this->assertNotNull($error);
        $this->assertStringContainsString('{{ content }}', $error);
    }

    public function test_rejects_multiple_placeholders(): void
    {
        $error = $this->validate('<p>{{ content }}</p><p>{{ content }}</p>');

        $this->assertNotNull($error);
        $this->assertStringContainsString('only include one', $error);
    }

    public function test_rejects_non_string_value(): void
    {
        $this->assertNotNull($this->validate(null));
        $this->assertNotNull($this->validate(123));
    }
}
