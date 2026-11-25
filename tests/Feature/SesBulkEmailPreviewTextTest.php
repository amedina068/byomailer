<?php

namespace Tests\Feature;

use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesBulkEmailService;
use App\Services\SesClientManager;
use App\Services\SesTenantService;
use Aws\SesV2\SesV2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SesBulkEmailPreviewTextTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{html: string|null}
     */
    private function captureCreatedTemplateHtml(?string $previewText): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = $user->newsletters()->create([
            'title' => 't',
            'status' => 'draft',
            'filters' => [],
        ]);
        $newsletter->message()->create([
            'user_id' => $user->id,
            'email_identity_id' => $identity->id,
            'subject' => 'Hi',
            'preview_text' => $previewText,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Body</p>',
            'text_content' => 'Body',
        ]);

        $capturedHtml = null;

        $v2Client = Mockery::mock(SesV2Client::class);
        $v2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedHtml) {
                $capturedHtml = $payload['TemplateContent']['Html'] ?? null;
            });

        $manager = Mockery::mock(SesClientManager::class);
        $manager->shouldReceive('v2ClientFor')->andReturn($v2Client);

        $service = new SesBulkEmailService($manager, Mockery::mock(SesTenantService::class));
        $service->createNewsletterTemplate($newsletter->fresh('message'), $user);

        return ['html' => $capturedHtml];
    }

    public function test_ses_template_contains_hidden_preheader_when_preview_text_set(): void
    {
        ['html' => $html] = $this->captureCreatedTemplateHtml('Tiny teaser');

        $this->assertNotNull($html);
        $this->assertMatchesRegularExpression(
            '#<div[^>]*display:\s*none[^>]*color:\s*transparent[^>]*>Tiny teaser#i',
            $html,
        );
        // Spacer chars after preview text consume inbox snippet space.
        $this->assertStringContainsString('&zwnj;', $html);

        $previewPos = strpos($html, 'Tiny teaser');
        $bodyPos = strpos($html, '<p>Body</p>');
        $this->assertNotFalse($previewPos);
        $this->assertNotFalse($bodyPos);
        $this->assertLessThan($bodyPos, $previewPos);
    }

    public function test_ses_template_preserves_handlebars_placeholders_in_preview_text(): void
    {
        ['html' => $html] = $this->captureCreatedTemplateHtml('Hi {{ name }}, fresh drop');

        // SES handles {{ name }} substitution per recipient — placeholder must survive verbatim.
        $this->assertStringContainsString('{{ name }}', $html);
        $this->assertStringContainsString('Hi {{ name }}, fresh drop', $html);
    }

    public function test_ses_template_skips_preheader_when_preview_text_blank(): void
    {
        ['html' => $htmlNull] = $this->captureCreatedTemplateHtml(null);
        $this->assertStringNotContainsString('display:none', $htmlNull);

        ['html' => $htmlBlank] = $this->captureCreatedTemplateHtml('   ');
        $this->assertStringNotContainsString('display:none', $htmlBlank);
    }
}
