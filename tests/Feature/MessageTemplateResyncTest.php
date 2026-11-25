<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\Block;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\SesBulkEmailService;
use App\Services\SesClientManager;
use App\Services\SesCredentials;
use App\Services\SesTenantService;
use App\Support\ContentSlot;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MessageTemplateResyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_composed_html_reflects_current_template_at_read_time(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $template = EmailTemplate::factory()->for($user)->create([
            'html_content' => '<header>OLD</header><p>{{ content }}</p>',
        ]);

        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'draft', 'filters' => []]);
        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => 's',
            'markdown_content' => '',
            'html_content' => ContentSlot::hydrate($template->html_content, '<p>Body</p>'),
            'text_content' => 'Body',
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>Body</p>',
        ]);

        $template->update(['html_content' => '<header>NEW</header><p>{{ content }}</p>']);

        $composed = $message->fresh()->composedHtml();

        $this->assertStringContainsString('<header>NEW</header>', $composed);
        $this->assertStringContainsString('<p>Body</p>', $composed);
        $this->assertStringNotContainsString('OLD', $composed);
    }

    public function test_composed_html_falls_back_to_stored_html_without_template(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'draft', 'filters' => []]);
        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => 's',
            'markdown_content' => '',
            'html_content' => '<p>Plain body</p>',
            'text_content' => 'Plain body',
        ]);

        $this->assertSame('<p>Plain body</p>', $message->composedHtml());
    }

    public function test_template_without_captured_slot_preserves_legacy_body(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $template = EmailTemplate::factory()->for($user)->create([
            'html_content' => '<header>Current</header>{{ content }}',
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();
        $message = $newsletter->message;
        $message->update([
            'email_template_id' => $template->id,
            'template_slot_content' => null,
            'html_content' => '<p>Legacy body</p>',
        ]);

        $this->assertSame('<p>Legacy body</p>', $message->fresh()->composedHtml());
    }

    public function test_command_resyncs_draft_newsletter_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $template = EmailTemplate::factory()->for($user)->create([
            'html_content' => '<header>OLD</header><p>{{ content }}</p>',
        ]);

        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'draft', 'filters' => []]);
        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => 's',
            'markdown_content' => '',
            'html_content' => ContentSlot::hydrate($template->html_content, '<p>Body</p>'),
            'text_content' => 'Body',
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>Body</p>',
        ]);

        $template->update(['html_content' => '<header>NEW</header><p>{{ content }}</p>']);

        $this->artisan('messages:resync-templates')->assertSuccessful();

        $message->refresh();
        $this->assertStringContainsString('<header>NEW</header>', $message->html_content);
        $this->assertStringNotContainsString('OLD', $message->html_content);
        $this->assertStringContainsString('<p>Body</p>', $message->html_content);
        $this->assertStringContainsString('NEW', $message->text_content);
    }

    public function test_command_skips_sent_newsletter_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $template = EmailTemplate::factory()->for($user)->create([
            'html_content' => '<header>OLD</header><p>{{ content }}</p>',
        ]);

        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'sent', 'filters' => []]);
        $original = ContentSlot::hydrate($template->html_content, '<p>Body</p>');
        $message = $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => 's',
            'markdown_content' => '',
            'html_content' => $original,
            'text_content' => 'Body',
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>Body</p>',
        ]);

        $template->update(['html_content' => '<header>NEW</header><p>{{ content }}</p>']);

        $this->artisan('messages:resync-templates')->assertSuccessful();

        $this->assertSame($original, $message->fresh()->html_content);
    }

    public function test_command_resyncs_sequence_step_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $template = EmailTemplate::factory()->for($user)->create([
            'html_content' => '<header>OLD</header><p>{{ content }}</p>',
        ]);

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message()->update([
            'html_content' => ContentSlot::hydrate($template->html_content, '<p>Step</p>'),
            'text_content' => 'Step',
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>Step</p>',
        ]);

        $template->update(['html_content' => '<header>NEW</header><p>{{ content }}</p>']);

        $this->artisan('messages:resync-templates')->assertSuccessful();

        $message = $step->message()->first();
        $this->assertStringContainsString('<header>NEW</header>', $message->html_content);
        $this->assertStringContainsString('<p>Step</p>', $message->html_content);
    }

    public function test_command_scopes_to_a_single_template(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $templateA = EmailTemplate::factory()->for($user)->create(['html_content' => '<p>A {{ content }}</p>']);
        $templateB = EmailTemplate::factory()->for($user)->create(['html_content' => '<p>B {{ content }}</p>']);

        $newsletterA = $user->newsletters()->create(['title' => 'a', 'status' => 'draft', 'filters' => []]);
        $messageA = $newsletterA->message()->create([
            'user_id' => $user->id,
            'subject' => 's',
            'markdown_content' => '',
            'html_content' => ContentSlot::hydrate($templateA->html_content, '<p>Body</p>'),
            'text_content' => 'Body',
            'email_template_id' => $templateA->id,
            'template_slot_content' => '<p>Body</p>',
        ]);

        $newsletterB = $user->newsletters()->create(['title' => 'b', 'status' => 'draft', 'filters' => []]);
        $originalB = ContentSlot::hydrate($templateB->html_content, '<p>Body</p>');
        $messageB = $newsletterB->message()->create([
            'user_id' => $user->id,
            'subject' => 's',
            'markdown_content' => '',
            'html_content' => $originalB,
            'text_content' => 'Body',
            'email_template_id' => $templateB->id,
            'template_slot_content' => '<p>Body</p>',
        ]);

        $templateA->update(['html_content' => '<p>A-NEW {{ content }}</p>']);
        $templateB->update(['html_content' => '<p>B-NEW {{ content }}</p>']);

        $this->artisan('messages:resync-templates', ['--template' => $templateA->id])->assertSuccessful();

        $this->assertStringContainsString('A-NEW', $messageA->fresh()->html_content);
        $this->assertSame($originalB, $messageB->fresh()->html_content);
    }

    public function test_current_template_uses_message_client_with_loaded_and_unloaded_relations(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create(['client_id' => $owner->client_id]);
        $otherUser = User::factory()->create();
        $sameClient = EmailTemplate::factory()->for($colleague)->create();
        $foreign = EmailTemplate::factory()->for($otherUser)->create();
        $system = EmailTemplate::factory()->system()->create();
        $message = Newsletter::factory()->for($owner)->create()->message()->withoutGlobalScopes()->first();
        $this->actingAs($otherUser);

        foreach ([$sameClient, $foreign, $system, null] as $template) {
            foreach ([false, true] as $loaded) {
                $candidate = clone $message;
                $candidate->email_template_id = $template?->id ?? 999999;
                $candidate->html_content = '<p>Stored fallback</p>';
                $candidate->text_content = 'Stored plain fallback';
                $candidate->template_slot_content = '<p>Slot body</p>';
                $candidate->unsetRelation('template');
                if ($loaded) {
                    $candidate->setRelation('template', $template);
                }

                if ($template === $sameClient || $template === $system) {
                    $this->assertTrue($candidate->currentTemplate()?->is($template) ?? false);
                    $this->assertStringContainsString('Slot body', $candidate->composedHtml());
                } else {
                    $this->assertNull($candidate->currentTemplate());
                    $this->assertSame('<p>Stored fallback</p>', $candidate->composedHtml());
                    $this->assertSame('Stored plain fallback', $candidate->composedText());
                }
            }
        }
    }

    public function test_sequence_raw_mail_uses_updated_template_and_expanded_footer_in_both_parts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
        $this->bindUpdatedTemplate($step->message, $user);
        $contact = Contact::factory()->for($user)->create(['name' => 'Ada']);
        $contactMail = ContactMail::factory()->for($contact)->forSequenceStep($step)->pending()->create([
            'message_id' => $step->message->id,
            'template_data' => [],
        ]);

        $contactMail->update(['template_data' => $step->message->templateData($contact, $contactMail)]);

        $content = (new NewsletterMail($contactMail))->content();
        $html = (string) $content->with['htmlContent'];
        $text = $content->with['textContent'];
        $this->assertCurrentTemplateParts($html, $text);
        $this->assertStringContainsString('for Ada', $html);
        $this->assertStringContainsString('for Ada', $text);
        $this->assertStringNotContainsString('{{ name }}', $html);
        $this->assertStringNotContainsString('{{ name }}', $text);
        $this->assertStringNotContainsString('<p>', $text);
    }

    public function test_ses_template_uses_updated_template_and_expanded_footer_in_both_parts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();
        $this->bindUpdatedTemplate($newsletter->message, $user);
        $contact = Contact::factory()->for($user)->create(['name' => 'Ada']);
        $contactMail = ContactMail::factory()->for($contact)->forNewsletter($newsletter)->pending()->create();
        $data = $newsletter->message->templateData($contact, $contactMail);
        $this->assertSame('Ada', $data['name'] ?? null);
        $captured = null;
        $ses = Mockery::mock(SesV2Client::class);
        $ses->shouldReceive('createEmailTemplate')->once()->andReturnUsing(function (array $payload) use (&$captured): Result {
            $captured = $payload['TemplateContent'];

            return new Result([]);
        });
        $clients = Mockery::mock(SesClientManager::class);
        $clients->shouldReceive('v2ClientFor')->with($user)->andReturn($ses);
        $service = new SesBulkEmailService($clients, Mockery::mock(SesTenantService::class), app(SesCredentials::class));

        $service->createNewsletterTemplate($newsletter, $user);

        $this->assertNotNull($captured);
        $this->assertCurrentTemplateParts($captured['Html'], $captured['Text']);
        foreach (['Html', 'Text'] as $part) {
            $this->assertMatchesRegularExpression('/for \\{\\{\\{? name \\}\\}\\}?/', $captured[$part]);
        }
        $this->assertStringNotContainsString('<p>', $captured['Text']);
    }

    public function test_unauthenticated_resync_handles_multiple_clients_states_filter_and_idempotence(): void
    {
        $messages = [];
        $templates = [];
        foreach (range(1, 2) as $index) {
            $user = User::factory()->create();
            $template = EmailTemplate::factory()->for($user)->create(['html_content' => '<header>OLD</header>{{ content }}']);
            $templates[] = $template;
            foreach (['draft', 'scheduled', 'sent'] as $status) {
                $newsletter = Newsletter::factory()->for($user)->create(['status' => $status]);
                $message = $newsletter->message()->withoutGlobalScopes()->first();
                $this->bindTemplate($message, $template);
                $messages[] = [$message, $status !== 'sent', $index === 1];
            }
            $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
            $step = SequenceStep::factory()->for($sequence)->create(['status' => 'live']);
            $stepMessage = $step->message()->withoutGlobalScopes()->first();
            $this->bindTemplate($stepMessage, $template);
            $messages[] = [$stepMessage, true, $index === 1];
            $template->update(['html_content' => '<header>NEW</header>{{ content }}']);
        }
        $this->assertGuest();

        $this->artisan('messages:resync-templates', ['--template' => $templates[0]->id])
            ->expectsOutput('Resynced 3 message(s) from their templates.')->assertSuccessful();
        foreach ($messages as [$message, $eligible, $firstClient]) {
            $this->assertStringContainsString($eligible && $firstClient ? 'NEW' : 'OLD', $message->fresh()->html_content);
        }
        $this->artisan('messages:resync-templates')
            ->expectsOutput('Resynced 3 message(s) from their templates.')->assertSuccessful();
        foreach ($messages as [$message, $eligible]) {
            $fresh = $message->fresh();
            $this->assertStringContainsString($eligible ? 'NEW' : 'OLD', $fresh->html_content);
            $this->assertStringContainsString($eligible ? 'NEW' : 'Stored plain', $fresh->text_content);
        }
        $timestamps = array_map(fn (array $entry): string => (string) $entry[0]->fresh()->updated_at, $messages);
        $this->travel(1)->minutes();
        $this->artisan('messages:resync-templates')
            ->expectsOutput('Resynced 0 message(s) from their templates.')->assertSuccessful();
        $this->assertSame($timestamps, array_map(fn (array $entry): string => (string) $entry[0]->fresh()->updated_at, $messages));
    }

    public function test_invalid_template_filter_cannot_resync_all_messages(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $message = $newsletter->message()->withoutGlobalScopes()->first();
        $this->bindUpdatedTemplate($message, $user);
        $original = $message->fresh()->getAttributes();

        foreach (['0', '', '-1', '1.5', 'not-an-id'] as $templateId) {
            $this->artisan('messages:resync-templates', ['--template' => $templateId])
                ->expectsOutput('The template id must be a positive integer.')
                ->assertExitCode(2);
            $this->assertSame($original, $message->fresh()->getAttributes());
        }
    }

    private function bindUpdatedTemplate(Message $message, User $user): void
    {
        $template = EmailTemplate::factory()->for($user)->create([
            'html_content' => '<header>OLD</header>{{ content }}',
        ]);
        $this->bindTemplate($message, $template);
        Block::factory()->for($user)->create([
            'name' => 'footer',
            'html_content' => '<p>Current footer <a href="https://example.com/footer">Footer link</a></p>',
        ]);
        $template->update(['html_content' => '<header>NEW header for {{ name }}</header>{{ content }}{{ block.footer }}']);
    }

    private function bindTemplate(Message $message, EmailTemplate $template): void
    {
        $message->update([
            'html_content' => ContentSlot::hydrate($template->html_content, '<p>Slot body</p>'),
            'text_content' => 'Stored plain',
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>Slot body</p>',
        ]);
    }

    private function assertCurrentTemplateParts(string $html, string $text): void
    {
        foreach ([$html, $text] as $part) {
            foreach (['NEW header', 'Slot body', 'Current footer', 'https://example.com/footer'] as $expected) {
                $this->assertStringContainsString($expected, $part);
            }
            $this->assertStringNotContainsString('OLD', $part);
            $this->assertStringNotContainsString('Stored plain', $part);
            $this->assertStringNotContainsString('block.footer', $part);
        }
    }
}
