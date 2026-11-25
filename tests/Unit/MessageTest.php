<?php

namespace Tests\Unit;

use App\Models\Block;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\SequenceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_template_data_from_detected_placeholders(): void
    {
        Carbon::setTestNow('2026-01-26 00:00:00');

        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $sequenceStep->load('message');
        $message = $sequenceStep->message;
        $message->update([
            'html_content' => '<p>Hello {{ first_name }} - {{ email }}.</p><p>{{ unsubscribe_url }}</p>',
            'text_content' => 'Plain {{ first_name }} copy',
        ]);
        $message->refresh();

        $contact = Contact::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'extra_attributes' => [
                'first_name' => 'Ada',
            ],
        ]);

        $contactMail = ContactMail::factory()->create([
            'contact_id' => $contact->id,
        ]);

        $data = $message->templateData($contact, $contactMail);

        $this->assertSame([
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'unsubscribe_url' => $contactMail->unsubscribe_link,
        ], $data);

        Carbon::setTestNow();
    }

    public function test_it_returns_empty_template_data_when_no_placeholders_are_present(): void
    {
        Carbon::setTestNow('2026-01-26 00:00:00');

        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $sequenceStep->load('message');
        $message = $sequenceStep->message;
        $message->update([
            'html_content' => '<p>No placeholders here.</p>',
            'text_content' => 'Still nothing',
        ]);
        $message->refresh();

        $contact = Contact::factory()->create();

        $contactMail = ContactMail::factory()->create([
            'contact_id' => $contact->id,
        ]);

        $this->assertSame([], $message->templateData($contact, $contactMail));

        Carbon::setTestNow();
    }

    public function test_it_replaces_template_variables_in_content(): void
    {
        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $message = $sequenceStep->message;

        $content = '<p>Hello {{ first_name }}, your email is {{ email }}.</p>';
        $data = [
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
        ];

        $result = $message->replaceTemplateVariables($content, $data);

        $this->assertSame('<p>Hello Ada, your email is ada@example.com.</p>', $result);
    }

    public function test_it_handles_whitespace_variations_in_placeholders(): void
    {
        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $message = $sequenceStep->message;

        $content = '{{first_name}} - {{ first_name }} - {{  first_name  }}';
        $data = ['first_name' => 'Ada'];

        $result = $message->replaceTemplateVariables($content, $data);

        $this->assertSame('Ada - Ada - Ada', $result);
    }

    public function test_it_leaves_unknown_placeholders_unchanged(): void
    {
        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $message = $sequenceStep->message;

        $content = 'Hello {{ first_name }}, {{ unknown_field }} here.';
        $data = ['first_name' => 'Ada'];

        $result = $message->replaceTemplateVariables($content, $data);

        $this->assertSame('Hello Ada, {{ unknown_field }} here.', $result);
    }

    public function test_it_joins_a_query_string_after_a_placeholder_whose_value_has_a_query(): void
    {
        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $message = $sequenceStep->message;

        $content = '<a href="{{ checkout_url }}?coupon=KEEPIT57">Buy</a>';

        $withQuery = $message->replaceTemplateVariables($content, ['checkout_url' => 'https://shop.test/checkout?token=abc']);
        $withoutQuery = $message->replaceTemplateVariables($content, ['checkout_url' => 'https://shop.test/checkout']);

        $this->assertSame('<a href="https://shop.test/checkout?token=abc&coupon=KEEPIT57">Buy</a>', $withQuery);
        $this->assertSame('<a href="https://shop.test/checkout?coupon=KEEPIT57">Buy</a>', $withoutQuery);
    }

    public function test_template_fields_excludes_block_variables(): void
    {
        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $sequenceStep->load('message');
        $message = $sequenceStep->message;
        $message->update([
            'html_content' => '<p>Hello {{ name }}, {{ block.welcome }} and {{ email }}.</p>',
        ]);
        $message->refresh();

        $fields = $message->templateFields();

        $this->assertContains('name', $fields);
        $this->assertContains('email', $fields);
        $this->assertNotContains('block.welcome', $fields);
    }

    public function test_template_fields_include_the_variables_of_a_block(): void
    {
        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $message = $sequenceStep->message;
        $message->update(['html_content' => '<p>{{ block.greeting }}</p>']);
        Block::factory()->create([
            'user_id' => $message->user_id,
            'name' => 'greeting',
            'html_content' => '<p>Hi {{ first_name }}</p>',
        ]);
        Block::factory()->create(['name' => 'greeting', 'html_content' => '<p>{{ other_client }}</p>']);

        $fields = $message->refresh()->templateFields();

        $this->assertContains('first_name', $fields);
        $this->assertNotContains('other_client', $fields);
    }

    public function test_it_returns_content_unchanged_when_data_is_empty(): void
    {
        $sequenceStep = SequenceStep::factory()->create();
        $this->actingAsClientOf($sequenceStep);
        $message = $sequenceStep->message;

        $content = '<p>Hello {{ first_name }}.</p>';

        $result = $message->replaceTemplateVariables($content, []);

        $this->assertSame('<p>Hello {{ first_name }}.</p>', $result);
    }

    private function actingAsClientOf(SequenceStep $sequenceStep): void
    {
        $this->actingAsTenant($sequenceStep->sequence()->withoutGlobalScopes()->value('client_id'));
    }
}
