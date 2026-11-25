<?php

namespace Tests\Unit\Rules;

use App\Rules\SafeTemplatedHtml;
use PHPUnit\Framework\TestCase;

class SafeTemplatedHtmlTest extends TestCase
{
    private function validate(string $html): ?string
    {
        $error = null;

        (new SafeTemplatedHtml)->validate('html_content', $html, function (string $message) use (&$error): void {
            $error = $message;
        });

        return $error;
    }

    public function test_a_template_that_wraps_the_slot_in_a_paragraph_is_allowed(): void
    {
        $html = '<h1>Hi</h1><p><div data-slot="content"><p>Body</p></div></p><a href="{{ unsubscribe_url }}">u</a>';

        $this->assertNull($this->validate($html));
    }

    public function test_html_without_a_slot_is_allowed(): void
    {
        $this->assertNull($this->validate('<p>Body</p>'));
    }

    public function test_a_script_inside_the_slot_is_refused(): void
    {
        $this->assertNotNull($this->validate('<p><div data-slot="content"><p>Body</p><script>x()</script></div></p>'));
    }

    public function test_a_script_outside_the_slot_is_refused(): void
    {
        $this->assertNotNull($this->validate('<script>x()</script><p><div data-slot="content"><p>Body</p></div></p>'));
    }

    public function test_an_event_handler_outside_the_slot_is_refused(): void
    {
        $this->assertNotNull($this->validate('<a onclick="x()">u</a><div data-slot="content"><p>Body</p></div>'));
    }

    public function test_html_without_a_slot_keeps_every_safe_html_check(): void
    {
        $this->assertNotNull($this->validate('<p>Body</p><iframe src="x"></iframe>'));
    }
}
