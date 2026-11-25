<?php

namespace Tests\Feature;

use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesBulkEmailService;
use App\Services\SesClientManager;
use App\Services\SesCredentials;
use App\Services\SesTenantService;
use Aws\SesV2\SesV2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SesBulkEmailSlotStrippingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_newsletter_template_strips_content_slot_wrapper(): void
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
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<header>H</header><div data-slot="content"><p>Hello</p></div><footer>F</footer>',
            'text_content' => 'Hello',
        ]);

        $capturedHtml = null;
        $capturedText = null;

        $v2Client = Mockery::mock(SesV2Client::class);
        $v2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedHtml, &$capturedText) {
                $capturedHtml = $payload['TemplateContent']['Html'] ?? null;
                $capturedText = $payload['TemplateContent']['Text'] ?? null;
            });

        $manager = Mockery::mock(SesClientManager::class);
        $manager->shouldReceive('v2ClientFor')->andReturn($v2Client);

        $tenant = Mockery::mock(SesTenantService::class);

        $service = new SesBulkEmailService($manager, $tenant, app(SesCredentials::class));
        $service->createNewsletterTemplate($newsletter->fresh('message'), $user);

        $this->assertNotNull($capturedHtml);
        $this->assertStringNotContainsString('data-slot', $capturedHtml);
        $this->assertStringContainsString('<p>Hello</p>', $capturedHtml);
        $this->assertStringContainsString('<header>H</header>', $capturedHtml);
        $this->assertStringContainsString('<footer>F</footer>', $capturedHtml);
    }
}
