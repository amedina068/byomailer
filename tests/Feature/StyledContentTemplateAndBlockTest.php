<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\Block;
use App\Models\EmailCredential;
use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\ContentSlot;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class StyledContentTemplateAndBlockTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_template_wrapped_newsletter_keeps_both_template_and_slot_inline_styles(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $templateHtml = '<table style="font-family: Verdana, sans-serif; color: #333">'
            .'<tr><td style="padding:24px">'
            .'{{ content }}'
            .'</td></tr></table>';

        $template = EmailTemplate::factory()->for($user)->create([
            'html_content' => $templateHtml,
        ]);

        $slotHtml = '<h2 style="font-family: Georgia, serif; font-size: 24px; color: #c8ff00">Slotted heading</h2>'
            .'<p style="font-family: Arial, sans-serif; font-size: 16px">Slotted body</p>';

        $composed = ContentSlot::hydrate($templateHtml, $slotHtml);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'html_content' => $composed,
            'email_template_id' => $template->id,
            'template_slot_content' => $slotHtml,
        ]);

        $rendered = $this->captureSentMail($user, $newsletter)->render();

        // Template-level styles survive.
        $this->assertStringContainsString('font-family: Verdana, sans-serif', $rendered);
        $this->assertStringContainsString('color: #333', $rendered);
        $this->assertStringContainsString('padding:24px', $rendered);

        // Slot-level styles survive.
        $this->assertStringContainsString('font-family: Georgia, serif', $rendered);
        $this->assertStringContainsString('font-size: 24px', $rendered);
        $this->assertStringContainsString('color: #c8ff00', $rendered);
        $this->assertStringContainsString('Slotted heading', $rendered);
        $this->assertStringContainsString('Slotted body', $rendered);

        // The slot wrapper itself is unwrapped (no leftover data-slot div).
        $this->assertStringNotContainsString('data-slot="content"', $rendered);
    }

    public function test_block_variable_substitution_preserves_inline_styles_in_block_html(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        Block::factory()->for($user)->create([
            'name' => 'signature',
            'html_content' => '<p style="font-family: Georgia, serif; color: #6b7280">— The Team</p>',
        ]);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'html_content' => '<p style="font-family: Arial">Hello.</p>{{ block.signature }}',
        ]);

        $rendered = $this->captureSentMail($user, $newsletter)->render();

        $this->assertStringContainsString('font-family: Arial', $rendered);
        $this->assertStringContainsString('font-family: Georgia, serif', $rendered);
        $this->assertStringContainsString('color: #6b7280', $rendered);
        $this->assertStringContainsString('— The Team', $rendered);
        $this->assertStringNotContainsString('{{ block.signature }}', $rendered);
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
