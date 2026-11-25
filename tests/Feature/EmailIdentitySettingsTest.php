<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesConfigurationSetService;
use App\Services\SesDomainIdentityService;
use App\Services\SesTenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class EmailIdentitySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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

    private function mockTenantService(): void
    {
        $tenantService = Mockery::mock(SesTenantService::class);
        $tenantService->shouldReceive('associateIdentityWithTenant')->andReturn(['success' => true]);
        $this->app->instance(SesTenantService::class, $tenantService);
    }

    public function test_user_can_view_email_identities_settings_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('identities.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/identities/Index')
            ->has('identities')
        );
    }

    public function test_email_identities_settings_page_shows_user_identities(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'test@example.com',
            'name' => 'Test Name',
        ]);

        $otherUser = User::factory()->create();
        EmailIdentity::factory()->for($otherUser)->create([
            'email' => 'other@example.com',
        ]);

        $response = $this->actingAs($user)->get(route('identities.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('identities', 1)
            ->has('identities.0', fn (Assert $id) => $id
                ->where('email', 'test@example.com')
                ->where('name', 'Test Name')
                ->etc()
            )
        );
    }

    public function test_user_can_create_email_identity_with_domain_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $this->mockConfigurationSetService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')
            ->withArgs(fn (string $domain, string $accessKey, string $secretKey, string $region): bool => $domain === 'example.com'
                && $accessKey === 'test-access-key'
                && $secretKey === 'test-secret-key'
                && $region === 'us-east-1')
            ->once()
            ->andReturn([
                'success' => true,
                'dkim_tokens' => ['token1', 'token2', 'token3'],
            ]);

        $service->shouldReceive('setMailFromDomain')
            ->withArgs(fn (string $domain, string $mailFrom, string $accessKey, string $secretKey, string $region): bool => $domain === 'example.com'
                && $mailFrom === 'mas.example.com'
                && $accessKey === 'test-access-key'
                && $secretKey === 'test-secret-key'
                && $region === 'us-east-1')
            ->once()
            ->andReturn(['success' => true]);

        $service->shouldReceive('getDkimRecords')
            ->with('example.com', ['token1', 'token2', 'token3'], null, 'us-east-1')
            ->once()
            ->andReturn([
                ['type' => 'CNAME', 'name' => 'token1._domainkey.example.com', 'value' => 'token1.dkim.amazonses.com'],
                ['type' => 'CNAME', 'name' => 'token2._domainkey.example.com', 'value' => 'token2.dkim.amazonses.com'],
                ['type' => 'CNAME', 'name' => 'token3._domainkey.example.com', 'value' => 'token3.dkim.amazonses.com'],
            ]);

        $service->shouldReceive('getMailFromRecords')
            ->with('mas.example.com', 'us-east-1')
            ->once()
            ->andReturn([
                ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
                ['type' => 'TXT', 'name' => 'mas.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
            ]);

        $service->shouldReceive('getDmarcRecord')
            ->with('example.com')
            ->once()
            ->andReturn(['type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;']);

        $this->app->instance(SesDomainIdentityService::class, $service);
        $this->mockTenantService();

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'new@example.com',
            'name' => 'New Identity',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('email_identities', [
            'user_id' => $user->id,
            'email' => 'new@example.com',
            'name' => 'New Identity',
            'domain' => 'example.com',
            'mail_from_subdomain' => 'mas.example.com',
            'region' => 'us-east-1',
            'identity_status' => IdentityStatus::Pending->value,
        ]);

        $identity = EmailIdentity::query()->where('email', 'new@example.com')->first();
        $this->assertCount(6, $identity->dnsRecords);

        $dkimRecords = $identity->dnsRecords()->where('purpose', 'dkim')->get();
        $this->assertCount(3, $dkimRecords);

        $mailFromRecords = $identity->dnsRecords()->where('purpose', 'mail_from')->get();
        $this->assertCount(2, $mailFromRecords);

        $dmarcRecords = $identity->dnsRecords()->where('purpose', 'dmarc')->get();
        $this->assertCount(1, $dmarcRecords);
    }

    public function test_user_cannot_create_email_identity_without_aws_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('identities.index'))
            ->postJson(route('identities.store'), [
                'email' => 'new@example.com',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('email_identities', [
            'email' => 'new@example.com',
        ]);
    }

    public function test_user_cannot_create_email_identity_with_invalid_email(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('identities.index'))
            ->postJson(route('identities.store'), [
                'email' => 'invalid-email',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_user_cannot_create_duplicate_email_identity(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this->actingAs($user)
            ->from(route('identities.index'))
            ->postJson(route('identities.store'), [
                'email' => 'existing@example.com',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_user_can_refresh_email_identity_status(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'verify@example.com',
            'domain' => 'example.com',
            'mail_from_subdomain' => 'mas.example.com',
            'identity_status' => IdentityStatus::Pending,
            'verified_at' => null,
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->withArgs(fn (string $domain, string $accessKey, string $secretKey, string $region): bool => $domain === 'example.com'
                && $accessKey === 'test-access-key'
                && $secretKey === 'test-secret-key'
                && $region === 'us-east-1')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'verified',
                'dkim_status' => 'SUCCESS',
                'mail_from_status' => 'SUCCESS',
            ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->patchJson(
            route('identities.refresh', $identity)
        );

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'identity_status' => 'verified',
        ]);

        $identity->refresh();
        $this->assertNotNull($identity->verified_at);
        $this->assertEquals(IdentityStatus::Verified, $identity->identity_status);
    }

    public function test_user_cannot_refresh_email_identity_without_aws_credentials(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'verify@example.com',
            'domain' => 'example.com',
        ]);

        $response = $this->actingAs($user)->patchJson(
            route('identities.refresh', $identity)
        );

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error' => 'No AWS credentials found.',
        ]);
    }

    public function test_user_cannot_refresh_other_users_email_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $otherUser = User::factory()->create();
        $identity = EmailIdentity::factory()->for($otherUser)->create([
            'domain' => 'other.com',
        ]);

        $response = $this->actingAs($user)->patchJson(
            route('identities.refresh', $identity)
        );

        $response->assertNotFound();
    }

    public function test_guest_cannot_access_email_identities_settings(): void
    {
        $response = $this->get(route('identities.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_domain_identity_creation_failure_returns_error(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Domain already exists in another AWS account.',
            ]);

        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)
            ->from(route('identities.index'))
            ->postJson(route('identities.store'), [
                'email' => 'new@example.com',
                'name' => 'New Identity',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('email_identities', [
            'email' => 'new@example.com',
        ]);
    }

    public function test_mail_from_domain_failure_returns_error(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')
            ->once()
            ->andReturn([
                'success' => true,
                'dkim_tokens' => ['token1', 'token2', 'token3'],
            ]);

        $service->shouldReceive('setMailFromDomain')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Failed to set MAIL FROM domain.',
            ]);

        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)
            ->from(route('identities.index'))
            ->postJson(route('identities.store'), [
                'email' => 'new@example.com',
                'name' => 'New Identity',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('email_identities', [
            'email' => 'new@example.com',
        ]);
    }

    public function test_store_creates_per_identity_configuration_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('createConfigurationSet')
            ->once()
            ->andReturn(['success' => true]);
        $configService->shouldReceive('getEventTypesForTracking')
            ->once()
            ->andReturn(['SEND', 'DELIVERY', 'OPEN', 'CLICK']);
        $configService->shouldReceive('createSnsEventDestination')
            ->once()
            ->andReturn(['success' => true]);
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')
            ->once()
            ->andReturn([
                'success' => true,
                'dkim_tokens' => ['token1', 'token2', 'token3'],
            ]);

        $service->shouldReceive('setMailFromDomain')
            ->once()
            ->andReturn(['success' => true]);

        $service->shouldReceive('getDkimRecords')
            ->once()
            ->andReturn([
                ['type' => 'CNAME', 'name' => 'token1._domainkey.example.com', 'value' => 'token1.dkim.amazonses.com'],
                ['type' => 'CNAME', 'name' => 'token2._domainkey.example.com', 'value' => 'token2.dkim.amazonses.com'],
                ['type' => 'CNAME', 'name' => 'token3._domainkey.example.com', 'value' => 'token3.dkim.amazonses.com'],
            ]);

        $service->shouldReceive('getMailFromRecords')
            ->once()
            ->andReturn([
                ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
                ['type' => 'TXT', 'name' => 'mas.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
            ]);

        $service->shouldReceive('getDmarcRecord')
            ->once()
            ->andReturn(['type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;']);

        $this->app->instance(SesDomainIdentityService::class, $service);
        $this->mockTenantService();

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'new@example.com',
            'name' => 'New Identity',
        ]);

        $response->assertOk();

        $identity = EmailIdentity::query()->where('email', 'new@example.com')->first();
        $this->assertNotNull($identity->configuration_set_name);
        $this->assertStringStartsWith('mailautomation-identity-', $identity->configuration_set_name);
        $this->assertTrue($identity->open_tracking_enabled);
        $this->assertTrue($identity->click_tracking_enabled);
    }

    public function test_store_returns_error_when_configuration_set_creation_fails(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('createConfigurationSet')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Configuration set limit exceeded.',
            ]);
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')
            ->once()
            ->andReturn([
                'success' => true,
                'dkim_tokens' => ['token1', 'token2', 'token3'],
            ]);

        $service->shouldReceive('setMailFromDomain')
            ->once()
            ->andReturn(['success' => true]);

        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)
            ->from(route('identities.index'))
            ->postJson(route('identities.store'), [
                'email' => 'new@example.com',
                'name' => 'New Identity',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_refresh_status_failure_returns_error(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'verify@example.com',
            'domain' => 'example.com',
            'identity_status' => IdentityStatus::Pending,
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Unable to fetch identity status.',
            ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->patchJson(
            route('identities.refresh', $identity)
        );

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error' => 'Unable to fetch identity status.',
        ]);
    }

    public function test_client_can_add_many_domains(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->count(5)->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $this->mockConfigurationSetService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')->once()->andReturn([
            'success' => true,
            'dkim_tokens' => ['token1', 'token2', 'token3'],
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $service->shouldReceive('getDkimRecords')->once()->andReturn([
            ['type' => 'CNAME', 'name' => 'token1._domainkey.newdomain.com', 'value' => 'token1.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'token2._domainkey.newdomain.com', 'value' => 'token2.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'token3._domainkey.newdomain.com', 'value' => 'token3.dkim.amazonses.com'],
        ]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'mas.newdomain.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'mas.newdomain.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.newdomain.com', 'value' => 'v=DMARC1; p=none;',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);
        $this->mockTenantService();

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'new@newdomain.com',
            'name' => 'New Identity',
        ]);

        $response->assertOk();
    }

    public function test_store_associates_identity_with_tenant(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->withTenant()->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
            'aws_account_id' => '123456789012',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $this->mockConfigurationSetService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')->once()->andReturn([
            'success' => true,
            'dkim_tokens' => ['token1', 'token2', 'token3'],
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $service->shouldReceive('getDkimRecords')->once()->andReturn([
            ['type' => 'CNAME', 'name' => 'token1._domainkey.example.com', 'value' => 'token1.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'token2._domainkey.example.com', 'value' => 'token2.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'token3._domainkey.example.com', 'value' => 'token3.dkim.amazonses.com'],
        ]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'mas.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $tenantService = Mockery::mock(SesTenantService::class);
        $tenantService->shouldReceive('associateIdentityWithTenant')
            ->with(
                'byomailer',
                'us-east-1',
                '123456789012',
                'example.com',
                Mockery::type('string'),
                'test-access-key',
                'test-secret-key',
                null,
            )
            ->once()
            ->andReturn(['success' => true]);
        $this->app->instance(SesTenantService::class, $tenantService);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'new@example.com',
            'name' => 'Tenant Test',
        ]);

        $response->assertOk();

        $identity = EmailIdentity::query()->where('email', 'new@example.com')->first();
        $this->assertNotNull($identity->tenant_associated_at);
    }

    public function test_store_works_without_tenant_when_credential_has_no_tenant(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
            'tenant_name' => null,
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
        ]);

        $this->mockConfigurationSetService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')->once()->andReturn([
            'success' => true,
            'dkim_tokens' => ['token1', 'token2', 'token3'],
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $service->shouldReceive('getDkimRecords')->once()->andReturn([
            ['type' => 'CNAME', 'name' => 'token1._domainkey.example.com', 'value' => 'token1.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'token2._domainkey.example.com', 'value' => 'token2.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'token3._domainkey.example.com', 'value' => 'token3.dkim.amazonses.com'],
        ]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'mas.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'new@example.com',
            'name' => 'No Tenant',
        ]);

        $response->assertOk();

        $identity = EmailIdentity::query()->where('email', 'new@example.com')->first();
        $this->assertNull($identity->tenant_associated_at);
    }

    public function test_refresh_updates_identity_to_verifying_when_not_fully_verified(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'verify@example.com',
            'domain' => 'example.com',
            'identity_status' => IdentityStatus::Pending,
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'pending',
                'dkim_status' => 'PENDING',
                'mail_from_status' => 'PENDING',
            ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->patchJson(
            route('identities.refresh', $identity)
        );

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'identity_status' => 'verifying',
        ]);

        $identity->refresh();
        $this->assertNull($identity->verified_at);
        $this->assertEquals(IdentityStatus::Verifying, $identity->identity_status);
    }
}
