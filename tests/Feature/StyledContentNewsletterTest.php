<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\EmailCredential;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class StyledContentNewsletterTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private function styledHtml(): string
    {
        return '<h1 style="font-family: Georgia, \'Times New Roman\', serif; font-size: 32px; color: #111827">Hi {{ name }}</h1>'
            .'<p style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; color: #1f2937">'
            .'Welcome <span style="color: #c8ff00; font-size: 18px">friend</span>.'
            .'</p>';
    }

    public function test_styled_html_round_trips_through_storage_unchanged(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $payload = [
            'title' => 'Styled Newsletter',
            'subject' => 'Hi {{ name }}',
            'html_content' => $this->styledHtml(),
            'ab_test_enabled' => false,
        ];

        $this->actingAs($user)
            ->patch(route('newsletters.content.update', $newsletter), $payload)
            ->assertRedirect();

        $stored = $newsletter->fresh()->message->html_content;

        $this->assertStringContainsString('font-family: Georgia', $stored);
        $this->assertStringContainsString('font-size: 32px', $stored);
        $this->assertStringContainsString('color: #111827', $stored);
        $this->assertStringContainsString('font-family: Arial, Helvetica, sans-serif', $stored);
        $this->assertStringContainsString('font-size: 16px', $stored);
        $this->assertStringContainsString('color: #c8ff00', $stored);
    }

    public function test_outbound_test_mail_render_keeps_inline_styles_and_replaces_template_variables(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'name' => 'Test Owner',
        ]);
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->forceFill(['content_variables' => ['name' => 'Test Owner']])->save();
        $newsletter->message()->update([
            'subject' => 'Hi {{ name }}',
            'html_content' => $this->styledHtml(),
            'text_content' => 'Plain text',
        ]);

        $capturedMail = $this->captureSentMail($user, $newsletter);

        $rendered = $capturedMail->render();

        $this->assertStringContainsString('font-family: Georgia', $rendered);
        $this->assertStringContainsString('font-size: 32px', $rendered);
        $this->assertStringContainsString('color: #111827', $rendered);
        $this->assertStringContainsString('font-family: Arial, Helvetica, sans-serif', $rendered);
        $this->assertStringContainsString('color: #c8ff00', $rendered);
        $this->assertStringContainsString('font-size: 18px', $rendered);

        // Template variable was replaced inside a styled element (heading + subject).
        $this->assertStringContainsString('Hi Test Owner', $rendered);
        $this->assertStringNotContainsString('{{ name }}', $rendered);

        $this->assertSame('[Test] Hi Test Owner', $capturedMail->envelope()->subject);
    }

    public function test_blade_does_not_escape_inline_style_quotes_in_rendered_html(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $html = '<p style="font-family: Georgia, &quot;Times New Roman&quot;, serif">Hello</p>';

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['html_content' => $html]);

        $rendered = $this->captureSentMail($user, $newsletter)->render();

        // The HtmlString wrapper means the body comes through without re-escaping.
        $this->assertStringContainsString('font-family: Georgia', $rendered);
        $this->assertStringContainsString('Times New Roman', $rendered);
    }

    public function test_styled_anchor_link_keeps_inline_color_after_utm_appending(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $html = '<p><a href="https://example.com/article" style="color: #c8ff00; font-weight: 700">Read more</a></p>';

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'html_content' => $html,
            'subject' => 'Monthly Update',
        ]);

        $rendered = $this->captureSentMail($user, $newsletter)->render();

        $this->assertStringContainsString('color: #c8ff00', $rendered);
        $this->assertStringContainsString('font-weight: 700', $rendered);
        $this->assertStringContainsString('utm_medium=mail', $rendered);
    }

    private function captureSentMail(User $user, Newsletter $newsletter): NewsletterMail
    {
        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $captured = null;
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (NewsletterMail $mail) use (&$captured): void {
                $captured = $mail;
            });

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $this->assertNotNull($captured);

        return $captured;
    }
}
