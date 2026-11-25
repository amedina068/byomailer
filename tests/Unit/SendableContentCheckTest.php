<?php

namespace Tests\Unit;

use App\Enums\AbVariant;
use App\Models\Message;
use App\Services\SendableContentCheck;
use PHPUnit\Framework\TestCase;

class SendableContentCheckTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function problems(?Message $message, AbVariant $variant = AbVariant::A): array
    {
        return (new SendableContentCheck)->problems($message, $variant);
    }

    /**
     * @return array<string, string>
     */
    private function warnings(?Message $message, AbVariant $variant = AbVariant::A): array
    {
        return (new SendableContentCheck)->warnings($message, $variant);
    }

    private function message(string $subject, string $html, string $text = ''): Message
    {
        return new Message(['subject' => $subject, 'html_content' => $html, 'text_content' => $text]);
    }

    public function test_complete_message_has_no_problems(): void
    {
        $this->assertSame([], $this->problems($this->message('Hello', '<p>Body</p>', 'Body')));
    }

    public function test_missing_message_reports_both_problems(): void
    {
        $this->assertSame(['subject', 'html_content'], array_keys($this->problems(null)));
    }

    public function test_whitespace_only_subject_is_a_problem(): void
    {
        $this->assertSame(['subject'], array_keys($this->problems($this->message("  \t ", '<p>Body</p>'))));
    }

    public function test_image_only_html_with_blank_text_is_a_problem(): void
    {
        $problems = $this->problems($this->message('Hi', '<div><img src="https://x.test/a.png"></div>&nbsp;', ' '));

        $this->assertSame(['html_content'], array_keys($problems));
    }

    public function test_style_only_html_with_blank_text_is_a_problem(): void
    {
        $problems = $this->problems($this->message('Hi', '<html><head><style>p { color: red; }</style></head><body></body></html>'));

        $this->assertSame(['html_content'], array_keys($problems));
    }

    public function test_image_only_html_with_text_part_is_not_a_problem(): void
    {
        $this->assertSame([], $this->problems($this->message('Hi', '<img src="https://x.test/a.png">', 'See our sale')));
    }

    public function test_image_alt_text_counts_as_content(): void
    {
        $this->assertSame([], $this->problems($this->message('Hi', '<img src="https://x.test/a.png" alt="Sale">')));
    }

    public function test_variant_b_problems_use_the_b_keys(): void
    {
        $problems = $this->problems($this->message('', '<img src="https://x.test/a.png">'), AbVariant::B);

        $this->assertSame(['subject_b', 'html_content_b'], array_keys($problems));
        $this->assertSame(SendableContentCheck::SUBJECT_B_MESSAGE, $problems['subject_b']);
    }

    public function test_body_without_an_unsubscribe_link_is_a_warning_and_not_a_problem(): void
    {
        $message = $this->message('Hi', '<p>Body</p>', 'Body');

        $this->assertSame([], $this->problems($message));
        $this->assertSame(['unsubscribe' => SendableContentCheck::UNSUBSCRIBE_WARNING], $this->warnings($message));
    }

    public function test_body_with_an_unsubscribe_link_has_no_warning(): void
    {
        $this->assertSame([], $this->warnings(
            $this->message('Hi', '<p>Body</p><a href="{{unsubscribe_url}}">Unsubscribe</a>'),
        ));
    }

    public function test_variant_b_without_an_unsubscribe_link_uses_the_b_warning_key(): void
    {
        $this->assertSame(['unsubscribe_b'], array_keys($this->warnings($this->message('Hi', '<p>Body</p>'), AbVariant::B)));
    }

    public function test_blank_body_is_a_problem_and_not_an_unsubscribe_warning(): void
    {
        $message = $this->message('Hi', '<p>&nbsp;</p>');

        $this->assertSame(['html_content'], array_keys($this->problems($message)));
        $this->assertSame([], $this->warnings($message));
    }

    public function test_the_unsubscribe_warning_names_the_one_click_header(): void
    {
        $this->assertStringContainsString('List-Unsubscribe', SendableContentCheck::UNSUBSCRIBE_WARNING);
    }
}
