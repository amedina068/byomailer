<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterPreviewTextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $messageOverrides
     */
    private function renderWithMessage(array $messageOverrides): string
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = $user->newsletters()->create(['title' => 't', 'status' => 'draft', 'filters' => []]);

        $message = $newsletter->message()->create(array_merge([
            'user_id' => $user->id,
            'email_identity_id' => $identity->id,
            'subject' => 'Hi {{ name }}',
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Hello {{ name }}</p>',
            'text_content' => 'Hello {{ name }}',
        ], $messageOverrides));

        $contact = Contact::factory()->for($user)->create(['email' => 'ada@example.com', 'name' => 'Ada']);

        $contactMail = ContactMail::create([
            'contact_id' => $contact->id,
            'message_id' => $message->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'subject' => $message->subject,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'template_data' => ['name' => 'Ada'],
            'status' => 'pending',
        ]);

        return (new NewsletterMail($contactMail))->render();
    }

    public function test_preview_text_injected_as_hidden_preheader_at_top_of_body(): void
    {
        $rendered = $this->renderWithMessage(['preview_text' => 'Tiny teaser before the body']);

        $this->assertStringContainsString('Tiny teaser before the body', $rendered);
        // Hidden preheader uses `color:transparent` (not white) so it stays
        // invisible even if a client ignores display:none.
        $this->assertMatchesRegularExpression(
            '#<div[^>]*display:\s*none[^>]*color:\s*transparent[^>]*>Tiny teaser before the body#i',
            $rendered,
        );
        // Spacer chars after preview text push body content out of the inbox snippet.
        $this->assertStringContainsString('&zwnj;', $rendered);

        $previewPos = strpos($rendered, 'Tiny teaser before the body');
        $bodyPos = strpos($rendered, 'Hello Ada');
        $this->assertNotFalse($previewPos);
        $this->assertNotFalse($bodyPos);
        $this->assertLessThan($bodyPos, $previewPos);
    }

    public function test_preview_text_resolves_template_variables(): void
    {
        $rendered = $this->renderWithMessage(['preview_text' => 'Hi {{ name }}, the latest is in']);

        $this->assertStringContainsString('Hi Ada, the latest is in', $rendered);
        $this->assertStringNotContainsString('{{ name }}', $rendered);
    }

    public function test_content_step_persists_preview_text(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'April',
            'subject' => 'Monthly Update',
            'preview_text' => 'A short teaser',
            'html_content' => '<p>Hi</p>',
        ])->assertRedirect();

        $this->assertSame('A short teaser', $newsletter->refresh()->message?->preview_text);
    }

    public function test_content_step_persists_preview_text_b_for_ab_variant(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'April',
            'subject' => 'A',
            'preview_text' => 'preheader A',
            'html_content' => '<p>A</p>',
            'ab_test_enabled' => true,
            'subject_b' => 'B',
            'preview_text_b' => 'preheader B',
            'html_content_b' => '<p>B</p>',
        ])->assertRedirect();

        $newsletter->refresh()->load(['message', 'messageB']);
        $this->assertSame('preheader A', $newsletter->message?->preview_text);
        $this->assertSame('preheader B', $newsletter->messageB?->preview_text);
    }

    public function test_safehtml_rejects_script_tag_in_preview_text(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'April',
            'subject' => 'Subject',
            'preview_text' => 'Hey <script>alert(1)</script>',
            'html_content' => '<p>Hi</p>',
        ])->assertSessionHasErrors('preview_text');

        $this->assertNull($newsletter->refresh()->message?->preview_text);
    }

    public function test_safehtml_rejects_onerror_handler_in_preview_text_b(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'April',
            'subject' => 'A',
            'html_content' => '<p>A</p>',
            'ab_test_enabled' => true,
            'subject_b' => 'B',
            'preview_text_b' => '<img src=x onerror=alert(1)>',
            'html_content_b' => '<p>B</p>',
        ])->assertSessionHasErrors('preview_text_b');
    }

    public function test_no_hidden_preheader_when_preview_text_is_blank(): void
    {
        $rendered = $this->renderWithMessage(['preview_text' => null]);

        $this->assertStringNotContainsString('display:none', $rendered);

        $rendered = $this->renderWithMessage(['preview_text' => '   ']);

        $this->assertStringNotContainsString('display:none', $rendered);
    }
}
