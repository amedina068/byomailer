<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\SesBulkEmailService;
use App\Services\SesClientManager;
use App\Services\SesTenantService;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * SES resolves `{{ }}` as handlebars against the per-recipient data, and block
 * names are never in that data. An unexpanded `{{ block.footer }}` therefore
 * renders as nothing, which silently dropped footers — the unsubscribe link
 * and postal address included — from every bulk newsletter.
 */
class SesBulkTemplateBlockTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_block_variables_are_expanded_before_the_template_reaches_ses(): void
    {
        [$user, $newsletter] = $this->makeNewsletter();

        Block::factory()->for($user)->create([
            'name' => 'footer',
            'html_content' => '<p>Acme Inc, 1 Main St</p>',
        ]);

        $newsletter->message->update([
            'subject' => 'Hello from {{ block.brand }}',
            'html_content' => '<p>Body</p>{{ block.footer }}',
            'preview_text' => 'A word from {{ block.brand }}',
            'text_content' => null,
        ]);

        Block::factory()->for($user)->create([
            'name' => 'brand',
            'html_content' => 'Acme',
        ]);

        $captured = $this->captureCreatedTemplate($user, $newsletter);

        $this->assertStringContainsString('Acme Inc, 1 Main St', $captured['TemplateContent']['Html']);
        $this->assertStringNotContainsString('block.footer', $captured['TemplateContent']['Html']);

        $this->assertSame('Hello from Acme', $captured['TemplateContent']['Subject']);
        $this->assertStringContainsString('A word from Acme', $captured['TemplateContent']['Html']);
    }

    public function test_a_block_belonging_to_another_client_is_never_used(): void
    {
        [$user, $newsletter] = $this->makeNewsletter();
        $otherUser = User::factory()->create();

        Block::factory()->for($otherUser)->create([
            'name' => 'footer',
            'html_content' => '<p>Other tenant footer</p>',
        ]);

        $newsletter->message->update([
            'html_content' => '<p>Body</p>{{ block.footer }}',
            'text_content' => null,
        ]);

        $captured = $this->captureCreatedTemplate($user, $newsletter);

        $this->assertStringNotContainsString('Other tenant footer', $captured['TemplateContent']['Html']);
    }

    public function test_the_text_part_keeps_link_urls(): void
    {
        [$user, $newsletter] = $this->makeNewsletter();

        $newsletter->message->update([
            'html_content' => '<p>Read the <a href="https://example.com/post">post</a>.</p>',
            'text_content' => null,
        ]);

        $captured = $this->captureCreatedTemplate($user, $newsletter);

        $this->assertStringContainsString('https://example.com/post', $captured['TemplateContent']['Text']);
    }

    public function test_a_named_link_reaches_the_ses_template_as_a_link_tag(): void
    {
        [$user, $newsletter] = $this->makeNewsletter();

        $newsletter->message->update([
            'html_content' => '<p><a href="https://example.com/sale" data-bm-link="pricing">Buy</a></p>',
            'text_content' => null,
        ]);

        $captured = $this->captureCreatedTemplate($user, $newsletter);

        $this->assertStringContainsString('ses:tags="bm_link:pricing;"', $captured['TemplateContent']['Html']);
        $this->assertStringNotContainsString('data-bm-link', $captured['TemplateContent']['Html']);
    }

    /**
     * @return array{0: User, 1: Newsletter}
     */
    private function makeNewsletter(): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user, 'user')->create([
            'type' => 'ses',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'cs-1',
        ]);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['email_identity_id' => $identity->id]);

        return [$user, $newsletter->fresh('message')];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureCreatedTemplate(User $user, Newsletter $newsletter): array
    {
        $captured = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$captured): Result {
                $captured = $payload;

                return new Result([]);
            });

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->with(Mockery::on(fn ($u) => $u->is($user)))
            ->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class));
        $service->createNewsletterTemplate($newsletter->fresh(), $user, $newsletter->message->fresh());

        $this->assertNotNull($captured);

        return $captured;
    }
}
