<?php

namespace Tests\Unit;

use App\Enums\WebhookEvent;
use Tests\TestCase;

class WebhookEventEnumTest extends TestCase
{
    public function test_values_returns_all_six_event_strings(): void
    {
        $this->assertSame([
            'contact.subscribed',
            'contact.unsubscribed',
            'contact.resubscribed',
            'newsletter.sent',
            'sequence_step.sent',
            'sequence.completed',
        ], WebhookEvent::values());
    }

    public function test_each_case_has_label_and_description(): void
    {
        foreach (WebhookEvent::cases() as $case) {
            $this->assertNotEmpty($case->label());
            $this->assertNotEmpty($case->description());
        }
    }

    public function test_cases_are_grouped(): void
    {
        $this->assertSame('contact', WebhookEvent::ContactSubscribed->group());
        $this->assertSame('newsletter', WebhookEvent::NewsletterSent->group());
        $this->assertSame('sequence', WebhookEvent::SequenceStepSent->group());
        $this->assertSame('sequence', WebhookEvent::SequenceCompleted->group());
    }
}
