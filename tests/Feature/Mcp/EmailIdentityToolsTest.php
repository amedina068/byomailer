<?php

namespace Tests\Feature\Mcp;

use App\Enums\IdentityStatus;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateEmailIdentityTool;
use App\Mcp\Tools\EmailIdentityDetailsTool;
use App\Mcp\Tools\ListEmailIdentitiesTool;
use App\Mcp\Tools\RefreshEmailIdentityStatusTool;
use App\Mcp\Tools\UpdateEmailIdentityTrackingTool;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesConfigurationSetService;
use App\Services\SesDomainIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailIdentityToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_email_identities_only_returns_the_authenticated_clients_identities(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create(['email' => 'mine@example.com']);
        EmailIdentity::factory()->create(['email' => 'other@elsewhere.com']);

        $response = ByoMailerServer::actingAs($user)->tool(ListEmailIdentitiesTool::class, []);

        $response->assertOk()
            ->assertSee('mine@example.com')
            ->assertSee('"count":1')
            ->assertDontSee('other@elsewhere.com');
    }

    public function test_list_email_identities_filters_by_status_and_search(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create(['email' => 'live@example.com']);
        EmailIdentity::factory()->for($user)->pending()->create(['email' => 'waiting@example.com']);
        EmailIdentity::factory()->for($user)->pending()->create(['email' => 'waiting@other.org', 'domain' => 'other.org']);

        $byStatus = ByoMailerServer::actingAs($user)->tool(ListEmailIdentitiesTool::class, [
            'identity_status' => IdentityStatus::Pending->value,
        ]);

        $byStatus->assertOk()
            ->assertSee('"count":2')
            ->assertDontSee('live@example.com');

        $bySearch = ByoMailerServer::actingAs($user)->tool(ListEmailIdentitiesTool::class, [
            'search' => 'other.org',
        ]);

        $bySearch->assertOk()
            ->assertSee('waiting@other.org')
            ->assertSee('"count":1');
    }

    public function test_list_email_identities_rejects_an_unknown_status(): void
    {
        $user = User::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(ListEmailIdentitiesTool::class, [
            'identity_status' => 'bogus',
        ]);

        $response->assertHasErrors();
    }

    public function test_email_identity_details_includes_dns_records(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->pending()->create([
            'domain' => 'example.com',
            'mail_from_subdomain' => 'mas.example.com',
            'region' => 'us-east-1',
        ]);

        $response = ByoMailerServer::actingAs($user)->tool(EmailIdentityDetailsTool::class, [
            'email_identity_id' => $identity->id,
        ]);

        $response->assertOk()
            ->assertSee('"mail_from_subdomain":"mas.example.com"')
            ->assertSee('_domainkey.example.com')
            ->assertSee('"purpose":"dkim"')
            ->assertSee('"purpose":"mail_from"')
            ->assertSee('"purpose":"dmarc"')
            ->assertSee('"verified":false');
    }

    public function test_email_identity_details_includes_reply_to(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'reply_to_email' => 'support@example.com',
            'reply_to_name' => 'Support Team',
        ]);

        ByoMailerServer::actingAs($user)->tool(EmailIdentityDetailsTool::class, [
            'email_identity_id' => $identity->id,
        ])->assertOk()
            ->assertSee('"reply_to_email":"support@example.com"')
            ->assertSee('"reply_to_name":"Support Team"');
    }

    public function test_email_identity_details_errors_for_another_clients_identity(): void
    {
        $user = User::factory()->create();
        $foreign = EmailIdentity::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(EmailIdentityDetailsTool::class, [
            'email_identity_id' => $foreign->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_create_email_identity_provisions_ses_and_returns_dns_records(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $this->mockConfigurationSetService();
        $this->mockDomainIdentityService(function (MockInterface $service): void {
            $service->shouldReceive('createDomainIdentity')
                ->once()
                ->andReturn(['success' => true, 'dkim_tokens' => ['tok1', 'tok2', 'tok3']]);
            $service->shouldReceive('setMailFromDomain')
                ->once()
                ->withArgs(fn (string $domain, string $mailFrom): bool => $domain === 'example.com' && $mailFrom === 'mas.example.com')
                ->andReturn(['success' => true]);
        });

        $response = ByoMailerServer::actingAs($user)->tool(CreateEmailIdentityTool::class, [
            'email' => 'hello@example.com',
            'name' => 'Example Team',
        ]);

        $response->assertOk()
            ->assertSee('hello@example.com')
            ->assertSee('"identity_status":"pending"')
            ->assertSee('tok1._domainkey.example.com')
            ->assertSee('refresh-email-identity-status');

        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();

        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame($user->client_id, $identity->client_id);
        $this->assertSame('example.com', $identity->domain);
        $this->assertNotNull($identity->configuration_set_name);
        $this->assertTrue($identity->open_tracking_enabled);
        $this->assertCount(6, $identity->dnsRecords);
    }

    public function test_create_email_identity_honours_custom_mail_from_and_tracking_flags(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['region' => 'us-east-1']);

        $this->mockConfigurationSetService();
        $this->mockDomainIdentityService(function (MockInterface $service): void {
            $service->shouldReceive('createDomainIdentity')
                ->once()
                ->andReturn(['success' => true, 'dkim_tokens' => ['tok1']]);
            $service->shouldReceive('setMailFromDomain')
                ->once()
                ->withArgs(fn (string $domain, string $mailFrom): bool => $mailFrom === 'bounce.example.com')
                ->andReturn(['success' => true]);
        });

        $response = ByoMailerServer::actingAs($user)->tool(CreateEmailIdentityTool::class, [
            'email' => 'hello@example.com',
            'mail_from_subdomain' => 'bounce.example.com',
            'open_tracking_enabled' => false,
            'click_tracking_enabled' => true,
        ]);

        $response->assertOk()->assertSee('"mail_from_subdomain":"bounce.example.com"');

        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();

        $this->assertFalse($identity->open_tracking_enabled);
        $this->assertTrue($identity->click_tracking_enabled);
    }

    public function test_create_email_identity_errors_without_aws_credentials(): void
    {
        $user = User::factory()->create();

        $response = ByoMailerServer::actingAs($user)->tool(CreateEmailIdentityTool::class, [
            'email' => 'hello@example.com',
        ]);

        $response->assertHasErrors()->assertSee('No AWS credentials found');
        $this->assertDatabaseMissing('email_identities', ['email' => 'hello@example.com']);
    }

    public function test_create_email_identity_rejects_a_duplicate_email_for_the_same_account(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->create(['email' => 'hello@example.com']);

        $response = ByoMailerServer::actingAs($user)->tool(CreateEmailIdentityTool::class, [
            'email' => 'hello@example.com',
        ]);

        $response->assertHasErrors();
        $this->assertSame(1, EmailIdentity::query()->withoutGlobalScopes()->where('email', 'hello@example.com')->count());
    }

    public function test_create_email_identity_rejects_an_email_that_a_teammate_added(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->for($owner->client)->create();
        EmailIdentity::factory()->for($owner)->create(['email' => 'hello@example.com']);

        $response = ByoMailerServer::actingAs($teammate)->tool(CreateEmailIdentityTool::class, [
            'email' => 'hello@example.com',
        ]);

        $response->assertHasErrors(['The email has already been taken.']);
        $this->assertSame(1, EmailIdentity::query()->withoutGlobalScopes()->where('email', 'hello@example.com')->count());
    }

    public function test_create_email_identity_surfaces_an_aws_failure(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $this->mockDomainIdentityService(function (MockInterface $service): void {
            $service->shouldReceive('createDomainIdentity')
                ->once()
                ->andReturn(['success' => false, 'error' => 'SES said no.']);
        });

        $response = ByoMailerServer::actingAs($user)->tool(CreateEmailIdentityTool::class, [
            'email' => 'hello@example.com',
        ]);

        $response->assertHasErrors()->assertSee('SES said no.');
        $this->assertDatabaseMissing('email_identities', ['email' => 'hello@example.com']);
    }

    public function test_refresh_email_identity_status_syncs_verification_from_aws(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->verifying()->create([
            'domain' => 'example.com',
            'mail_from_subdomain' => 'mas.example.com',
            'region' => 'us-east-1',
        ]);

        $this->mockDomainIdentityService(function (MockInterface $service): void {
            $service->shouldReceive('getIdentityStatus')
                ->once()
                ->withArgs(fn (string $domain): bool => $domain === 'example.com')
                ->andReturn([
                    'success' => true,
                    'status' => 'verified',
                    'dkim_status' => 'SUCCESS',
                    'mail_from_status' => 'SUCCESS',
                ]);
        });

        $response = ByoMailerServer::actingAs($user)->tool(RefreshEmailIdentityStatusTool::class, [
            'email_identity_id' => $identity->id,
        ]);

        $response->assertOk()
            ->assertSee('"identity_status":"verified"')
            ->assertSee('"dkim_status":"SUCCESS"')
            ->assertSee('"mail_from_status":"SUCCESS"')
            ->assertSee("Identity #{$identity->id} is verified.");

        $identity->refresh();

        $this->assertSame(IdentityStatus::Verified, $identity->identity_status);
        $this->assertNotNull($identity->verified_at);
        $this->assertTrue($identity->dnsRecords()->where('purpose', 'dkim')->get()->every(fn ($record) => $record->verified_at !== null));
    }

    public function test_refresh_email_identity_status_surfaces_an_aws_failure(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->verifying()->create();

        $this->mockDomainIdentityService(function (MockInterface $service): void {
            $service->shouldReceive('getIdentityStatus')
                ->once()
                ->andReturn(['success' => false, 'error' => 'Unable to fetch identity status.']);
        });

        $response = ByoMailerServer::actingAs($user)->tool(RefreshEmailIdentityStatusTool::class, [
            'email_identity_id' => $identity->id,
        ]);

        $response->assertHasErrors()->assertSee('Unable to fetch identity status.');
        $this->assertSame(IdentityStatus::Verifying, $identity->fresh()->identity_status);
    }

    public function test_refresh_email_identity_status_errors_for_another_clients_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $foreign = EmailIdentity::factory()->verifying()->create();

        $response = ByoMailerServer::actingAs($user)->tool(RefreshEmailIdentityStatusTool::class, [
            'email_identity_id' => $foreign->id,
        ]);

        $response->assertHasErrors();
    }

    public function test_update_email_identity_tracking_updates_ses_and_the_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('getEventTypesForTracking')
            ->once()
            ->with(true, false)
            ->andReturn(['SEND', 'DELIVERY', 'OPEN']);
        $configService->shouldReceive('updateSnsEventDestination')
            ->once()
            ->andReturn(['success' => true]);
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $response = ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityTrackingTool::class, [
            'email_identity_id' => $identity->id,
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => false,
        ]);

        $response->assertOk()
            ->assertSee('"open_tracking_enabled":true')
            ->assertSee('"click_tracking_enabled":false');

        $identity->refresh();

        $this->assertTrue($identity->open_tracking_enabled);
        $this->assertFalse($identity->click_tracking_enabled);
    }

    public function test_update_email_identity_tracking_errors_without_a_configuration_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create(['configuration_set_name' => null]);

        $response = ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityTrackingTool::class, [
            'email_identity_id' => $identity->id,
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
        ]);

        $response->assertHasErrors()->assertSee('No configuration set found');
    }

    public function test_update_email_identity_tracking_requires_both_flags(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create();

        $response = ByoMailerServer::actingAs($user)->tool(UpdateEmailIdentityTrackingTool::class, [
            'email_identity_id' => $identity->id,
            'open_tracking_enabled' => true,
        ]);

        $response->assertHasErrors();
    }

    private function mockConfigurationSetService(): void
    {
        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('createConfigurationSet')->andReturn(['success' => true]);
        $configService->shouldReceive('getEventTypesForTracking')->andReturn(['SEND', 'DELIVERY', 'OPEN', 'CLICK']);
        $configService->shouldReceive('createSnsEventDestination')->andReturn(['success' => true]);
        $configService->shouldReceive('deleteConfigurationSet')->andReturn(['success' => true]);
        $this->app->instance(SesConfigurationSetService::class, $configService);
    }

    /**
     * @param  callable(MockInterface): void  $expectations
     */
    private function mockDomainIdentityService(callable $expectations): void
    {
        $service = Mockery::mock(SesDomainIdentityService::class);
        $expectations($service);

        $service->shouldReceive('getDkimRecords')
            ->andReturnUsing(fn (string $domain, array $tokens): array => array_map(fn (string $token): array => [
                'type' => 'CNAME',
                'name' => "{$token}._domainkey.{$domain}",
                'value' => "{$token}.dkim.amazonses.com",
            ], $tokens));
        $service->shouldReceive('getMailFromRecords')
            ->andReturnUsing(fn (string $mailFrom, string $region): array => [
                ['type' => 'MX', 'name' => $mailFrom, 'value' => "feedback-smtp.{$region}.amazonses.com", 'priority' => 10],
                ['type' => 'TXT', 'name' => $mailFrom, 'value' => 'v=spf1 include:amazonses.com ~all'],
            ]);
        $service->shouldReceive('getDmarcRecord')
            ->andReturnUsing(fn (string $domain): array => [
                'type' => 'TXT',
                'name' => "_dmarc.{$domain}",
                'value' => 'v=DMARC1; p=none;',
            ]);

        $this->app->instance(SesDomainIdentityService::class, $service);
    }
}
