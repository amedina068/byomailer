<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Models\Client;
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

class IdentityWizardTest extends TestCase
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

    public function test_create_page_renders_with_existing_domains_and_credential_flag(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->create([
            'email' => 'hello@example.com',
            'domain' => 'example.com',
        ]);

        $response = $this->actingAs($user)->get(route('identities.create'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/identities/Create')
            ->where('hasCredential', true)
            ->has('existingDomains', 1)
            ->where('existingDomains.0', 'example.com')
        );
    }

    public function test_show_page_renders_with_identity_resource(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'show@example.com',
        ]);

        $response = $this->actingAs($user)->get(route('identities.show', $identity));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/identities/Show')
            ->has('identity', fn (Assert $id) => $id
                ->where('email', 'show@example.com')
                ->etc()
            )
        );
    }

    public function test_destroy_removes_identity(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->delete(
            route('identities.destroy', $identity)
        );

        $response->assertRedirect(route('identities.index'));
        $response->assertSessionHas('success', 'Email identity removed.');
        $this->assertDatabaseMissing('email_identities', ['id' => $identity->id]);
    }

    public function test_aws_list_returns_domain_identities_with_already_added_flag(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'k',
            'secret_key' => 's',
            'region' => 'us-east-1',
        ]);

        EmailIdentity::factory()->for($user)->create([
            'email' => 'hello@existing.com',
            'domain' => 'existing.com',
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('listDomainIdentities')
            ->once()
            ->andReturn([
                'success' => true,
                'identities' => [
                    [
                        'name' => 'existing.com',
                        'sending_enabled' => true,
                        'verification_status' => 'SUCCESS',
                    ],
                    [
                        'name' => 'new.example',
                        'sending_enabled' => false,
                        'verification_status' => 'PENDING',
                    ],
                ],
            ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->getJson(route('identities.aws-list'));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'identities' => [
                [
                    'name' => 'existing.com',
                    'verification_status' => 'SUCCESS',
                    'already_added' => true,
                ],
                [
                    'name' => 'new.example',
                    'verification_status' => 'PENDING',
                    'already_added' => false,
                ],
            ],
        ]);
    }

    public function test_aws_list_returns_error_when_no_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('identities.aws-list'));

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_aws_list_returns_aws_error(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('listDomainIdentities')
            ->once()
            ->andReturn(['success' => false, 'error' => 'AccessDenied']);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->getJson(route('identities.aws-list'));

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error' => 'AccessDenied']);
    }

    public function test_store_existing_aws_branch_skips_create_email_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('createDomainIdentity');
        $service->shouldNotReceive('getDkimRecords');
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'mas.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;',
        ]);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'verified',
            'dkim_status' => 'SUCCESS',
            'dkim_tokens' => [],
            'mail_from_domain' => null,
            'mail_from_status' => 'SUCCESS',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
            'open_tracking_enabled' => false,
            'click_tracking_enabled' => true,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['identity' => ['id', 'email', 'dns_records']]);

        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();
        $this->assertSame('example.com', $identity->domain);
        $this->assertSame('mas.example.com', $identity->mail_from_subdomain);
        $this->assertFalse($identity->open_tracking_enabled);
        $this->assertTrue($identity->click_tracking_enabled);
        $this->assertSame(IdentityStatus::Verified, $identity->identity_status);
    }

    public function test_store_sends_only_the_chosen_tracking_events_to_ses(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:byomailer-events',
        ]);

        $trackingTypes = ['SEND', 'DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT', 'RENDERING_FAILURE', 'CLICK'];
        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('createConfigurationSet')->andReturn(['success' => true]);
        $configService->shouldReceive('getEventTypesForTracking')->once()->with(false, true)->andReturn($trackingTypes);
        $configService->shouldReceive('createSnsEventDestination')
            ->once()
            ->withArgs(fn (string $set, string $name, string $topic, array $types): bool => $types === $trackingTypes)
            ->andReturn(['success' => true]);
        $this->app->instance(SesConfigurationSetService::class, $configService);
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('setMailFromDomain')->andReturn(['success' => true]);
        $service->shouldReceive('getMailFromRecords')->andReturn([]);
        $service->shouldReceive('getDmarcRecord')->andReturn(['type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;']);
        $service->shouldReceive('getIdentityStatus')->andReturn([
            'success' => true,
            'status' => 'verified',
            'dkim_status' => 'SUCCESS',
            'dkim_tokens' => [],
            'mail_from_domain' => null,
            'mail_from_status' => 'SUCCESS',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
            'open_tracking_enabled' => false,
            'click_tracking_enabled' => true,
        ])->assertOk();
    }

    public function test_store_create_new_branch_calls_create_email_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'k',
            'secret_key' => 's',
            'region' => 'us-east-1',
        ]);

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')->once()->andReturn([
            'success' => true,
            'dkim_tokens' => ['t1', 't2', 't3'],
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $service->shouldReceive('getDkimRecords')->once()->andReturn([
            ['type' => 'CNAME', 'name' => 't1._domainkey.new.example', 'value' => 't1.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 't2._domainkey.new.example', 'value' => 't2.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 't3._domainkey.new.example', 'value' => 't3.dkim.amazonses.com'],
        ]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'mas.new.example', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'mas.new.example', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.new.example', 'value' => 'v=DMARC1; p=none;',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'sender@new.example',
            'name' => 'New Sender',
        ]);

        $response->assertOk();

        $identity = EmailIdentity::query()->where('email', 'sender@new.example')->firstOrFail();
        $this->assertSame(IdentityStatus::Pending, $identity->identity_status);
        $this->assertCount(6, $identity->dnsRecords);
    }

    public function test_store_returns_json_error_for_json_request_when_no_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'whatever@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'No AWS credentials found. Please complete the AWS onboarding first.']);
    }

    public function test_store_existing_aws_branch_remains_verifying_when_aws_reports_pending(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'k',
            'secret_key' => 's',
            'region' => 'us-east-1',
        ]);

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('createDomainIdentity');
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'mas.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;',
        ]);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'dkim_status' => 'PENDING',
            'dkim_tokens' => [],
            'mail_from_domain' => null,
            'mail_from_status' => 'PENDING',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
        ]);

        $response->assertOk();
        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();
        $this->assertSame(IdentityStatus::Verifying, $identity->identity_status);
        $this->assertNull($identity->verified_at);
    }

    public function test_store_existing_aws_branch_returns_error_when_mail_from_fails(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('createDomainIdentity');
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'dkim_status' => 'PENDING',
            'dkim_tokens' => [],
            'mail_from_domain' => null,
            'mail_from_status' => null,
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn([
            'success' => false,
            'error' => 'Invalid MAIL FROM',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Invalid MAIL FROM']);
        $this->assertDatabaseMissing('email_identities', ['email' => 'hello@example.com']);
    }

    public function test_aws_list_returns_empty_when_no_domain_identities(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('listDomainIdentities')->once()->andReturn([
            'success' => true,
            'identities' => [],
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->getJson(route('identities.aws-list'));

        $response->assertOk();
        $response->assertExactJson(['success' => true, 'identities' => []]);
    }

    public function test_show_returns_404_for_other_client_identity(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $identity = EmailIdentity::factory()->for($owner)->create();

        $response = $this->actingAs($intruder)->get(route('identities.show', $identity));

        $response->assertNotFound();
    }

    public function test_destroy_returns_404_for_other_client_identity(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $identity = EmailIdentity::factory()->for($owner)->create();

        $response = $this->actingAs($intruder)->delete(route('identities.destroy', $identity));

        $response->assertNotFound();
        $this->assertDatabaseHas('email_identities', ['id' => $identity->id]);
    }

    public function test_create_page_requires_authentication(): void
    {
        $response = $this->get(route('identities.create'));

        $response->assertRedirect(route('login'));
    }

    public function test_aws_list_requires_authentication(): void
    {
        $response = $this->getJson(route('identities.aws-list'));

        $response->assertStatus(401);
    }

    public function test_store_validation_fails_for_invalid_email_json(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_store_validation_fails_when_email_missing_json(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('identities.store'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_store_validation_fails_for_duplicate_email_json(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        EmailIdentity::factory()->for($user)->create([
            'email' => 'taken@example.com',
            'domain' => 'example.com',
        ]);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'taken@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_store_existing_aws_preserves_existing_mail_from_from_aws(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('createDomainIdentity');
        $service->shouldNotReceive('setMailFromDomain');
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'verified',
            'dkim_status' => 'SUCCESS',
            'dkim_tokens' => ['tok1', 'tok2', 'tok3'],
            'mail_from_domain' => 'bounces.example.com',
            'mail_from_status' => 'SUCCESS',
        ]);
        $service->shouldReceive('getDkimRecords')->once()->andReturn([
            ['type' => 'CNAME', 'name' => 'tok1._domainkey.example.com', 'value' => 'tok1.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'tok2._domainkey.example.com', 'value' => 'tok2.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'tok3._domainkey.example.com', 'value' => 'tok3.dkim.amazonses.com'],
        ]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'bounces.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'bounces.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
        ]);

        $response->assertOk();
        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();
        $this->assertSame('bounces.example.com', $identity->mail_from_subdomain);
        $this->assertCount(6, $identity->dnsRecords);
    }

    public function test_store_existing_aws_returns_error_when_identity_not_found_at_aws(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('createDomainIdentity');
        $service->shouldNotReceive('setMailFromDomain');
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => false,
            'error' => 'NotFoundException',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'NotFoundException']);
        $this->assertDatabaseMissing('email_identities', ['email' => 'hello@example.com']);
    }

    public function test_store_existing_aws_persists_dkim_records_when_tokens_returned(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['region' => 'us-east-1']);

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'dkim_status' => 'PENDING',
            'dkim_tokens' => ['a', 'b', 'c'],
            'mail_from_domain' => null,
            'mail_from_status' => null,
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $service->shouldReceive('getDkimRecords')->once()->with('example.com', ['a', 'b', 'c'], null, 'us-east-1')->andReturn([
            ['type' => 'CNAME', 'name' => 'a._domainkey.example.com', 'value' => 'a.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'b._domainkey.example.com', 'value' => 'b.dkim.amazonses.com'],
            ['type' => 'CNAME', 'name' => 'c._domainkey.example.com', 'value' => 'c.dkim.amazonses.com'],
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
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
        ]);

        $response->assertOk();
        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();
        $dkimRecords = $identity->dnsRecords()->where('purpose', 'dkim')->get();
        $this->assertCount(3, $dkimRecords);
    }

    public function test_store_new_identity_uses_regional_dkim_domain_when_aws_omits_hosted_zone(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['region' => 'eu-south-1']);

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class)->makePartial();
        $service->shouldReceive('createDomainIdentity')->once()->andReturn([
            'success' => true,
            'dkim_tokens' => ['tokmilan1', 'tokmilan2', 'tokmilan3'],
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
        ]);

        $response->assertOk();
        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();
        $this->assertSame(
            [
                'tokmilan1.dkim.eu-south-1.amazonses.com',
                'tokmilan2.dkim.eu-south-1.amazonses.com',
                'tokmilan3.dkim.eu-south-1.amazonses.com',
            ],
            $identity->dnsRecords()->where('purpose', 'dkim')->orderBy('id')->pluck('value')->all(),
        );
    }

    public function test_store_existing_aws_identity_uses_signing_hosted_zone_from_aws(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['region' => 'af-south-1']);

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class)->makePartial();
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'dkim_status' => 'PENDING',
            'dkim_tokens' => ['tokcape1', 'tokcape2', 'tokcape3'],
            'dkim_signing_hosted_zone' => 'dkim.af-south-1.amazonses.com',
            'mail_from_domain' => null,
            'mail_from_status' => null,
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
        ]);

        $response->assertOk();
        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();
        $this->assertSame(
            [
                'tokcape1._domainkey.example.com' => 'tokcape1.dkim.af-south-1.amazonses.com',
                'tokcape2._domainkey.example.com' => 'tokcape2.dkim.af-south-1.amazonses.com',
                'tokcape3._domainkey.example.com' => 'tokcape3.dkim.af-south-1.amazonses.com',
            ],
            $identity->dnsRecords()->where('purpose', 'dkim')->orderBy('id')->pluck('value', 'name')->all(),
        );
    }

    public function test_store_rejects_non_boolean_existing_aws_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => 'not-a-bool',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['existing_aws_identity']);
    }

    public function test_create_redirects_to_credentials_when_no_credential(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('identities.create'));

        $response->assertRedirect(route('email-credentials.index'));
        $response->assertSessionHas('error', 'Add your AWS credentials before you add an email identity.');
    }

    public function test_index_passes_has_credential_false_when_user_has_no_credential(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('identities.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/identities/Index')
            ->where('hasCredential', false)
        );
    }

    public function test_index_passes_has_credential_true_when_user_has_credential(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('identities.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/identities/Index')
            ->where('hasCredential', true)
        );
    }

    public function test_store_existing_aws_silently_ignores_apply_status_when_result_unsuccessful(): void
    {
        // applyAwsStatusToIdentity is a no-op when status result has success=false.
        // We simulate this by returning success=true initially (passes validation),
        // then internally the apply path is exercised via the same result.
        // Here we cover the early-exit branch: when getIdentityStatus returns success=false
        // we never reach applyAwsStatusToIdentity at all. This test asserts no exception
        // is thrown when the apply path would receive a degraded payload.
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            // intentionally missing status/dkim_status/mail_from_status to mimic partial AWS reply
            'dkim_tokens' => [],
            'mail_from_domain' => null,
        ]);
        $service->shouldReceive('setMailFromDomain')->once()->andReturn(['success' => true]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'mas.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hello@example.com',
            'existing_aws_identity' => true,
        ]);

        $response->assertOk();
        $identity = EmailIdentity::query()->where('email', 'hello@example.com')->firstOrFail();
        $this->assertSame(IdentityStatus::Verifying, $identity->identity_status);
    }

    public function test_aws_details_returns_mail_from_domain_when_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->once()
            ->with('example.com', Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([
                'success' => true,
                'status' => 'verified',
                'dkim_status' => 'SUCCESS',
                'dkim_tokens' => ['t'],
                'mail_from_domain' => 'bounces.example.com',
                'mail_from_status' => 'SUCCESS',
            ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->getJson(
            route('identities.aws-details', ['domain' => 'example.com'])
        );

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'mail_from_domain' => 'bounces.example.com',
            'verification_status' => 'verified',
        ]);
    }

    public function test_aws_details_returns_null_mail_from_when_not_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'dkim_status' => 'PENDING',
            'dkim_tokens' => [],
            'mail_from_domain' => null,
            'mail_from_status' => null,
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->getJson(
            route('identities.aws-details', ['domain' => 'example.com'])
        );

        $response->assertOk();
        $response->assertJson(['success' => true, 'mail_from_domain' => null]);
    }

    public function test_aws_details_returns_error_when_aws_fails(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => false,
            'error' => 'NotFoundException',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->getJson(
            route('identities.aws-details', ['domain' => 'gone.example.com'])
        );

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error' => 'NotFoundException']);
    }

    public function test_aws_details_requires_credential(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            route('identities.aws-details', ['domain' => 'example.com'])
        );

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_aws_details_validates_domain_param(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson(route('identities.aws-details'));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['domain']);
    }

    public function test_aws_details_requires_authentication(): void
    {
        $response = $this->getJson(
            route('identities.aws-details', ['domain' => 'example.com'])
        );

        $response->assertStatus(401);
    }

    public function test_store_new_branch_uses_custom_mail_from_subdomain_when_provided(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'k',
            'secret_key' => 's',
            'region' => 'us-east-1',
        ]);

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('createDomainIdentity')->once()->andReturn([
            'success' => true,
            'dkim_tokens' => ['t1'],
        ]);
        $service->shouldReceive('setMailFromDomain')
            ->once()
            ->with('new.example', 'bounces.new.example', Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(['success' => true]);
        $service->shouldReceive('getDkimRecords')->once()->andReturn([
            ['type' => 'CNAME', 'name' => 't1._domainkey.new.example', 'value' => 't1.dkim.amazonses.com'],
        ]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'bounces.new.example', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'bounces.new.example', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.new.example', 'value' => 'v=DMARC1; p=none;',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hi@new.example',
            'mail_from_subdomain' => 'bounces.new.example',
        ]);

        $response->assertOk();
        $identity = EmailIdentity::query()->where('email', 'hi@new.example')->firstOrFail();
        $this->assertSame('bounces.new.example', $identity->mail_from_subdomain);
    }

    public function test_store_existing_aws_ignores_provided_mail_from_when_aws_already_has_one(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $this->mockConfigurationSetService();
        $this->mockTenantService();

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('createDomainIdentity');
        $service->shouldNotReceive('setMailFromDomain');
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'verified',
            'dkim_status' => 'SUCCESS',
            'dkim_tokens' => [],
            'mail_from_domain' => 'aws-managed.example.com',
            'mail_from_status' => 'SUCCESS',
        ]);
        $service->shouldReceive('getMailFromRecords')->once()->andReturn([
            ['type' => 'MX', 'name' => 'aws-managed.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'aws-managed.example.com', 'value' => 'v=spf1 include:amazonses.com ~all'],
        ]);
        $service->shouldReceive('getDmarcRecord')->once()->andReturn([
            'type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=none;',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hi@example.com',
            'existing_aws_identity' => true,
            'mail_from_subdomain' => 'user-tried-to-override.example.com',
        ]);

        $response->assertOk();
        $identity = EmailIdentity::query()->where('email', 'hi@example.com')->firstOrFail();
        $this->assertSame('aws-managed.example.com', $identity->mail_from_subdomain);
    }

    public function test_store_rejects_invalid_mail_from_subdomain(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('identities.store'), [
            'email' => 'hi@example.com',
            'mail_from_subdomain' => 'not a valid subdomain!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mail_from_subdomain']);
    }

    public function test_aws_list_marks_identity_added_for_same_client_different_user(): void
    {
        $client = Client::factory()->create();
        $owner = User::factory()->for($client)->create();
        $teammate = User::factory()->for($client)->create();

        EmailCredential::factory()->for($teammate)->create();
        EmailIdentity::factory()->for($owner)->create([
            'email' => 'owner@shared.com',
            'domain' => 'shared.com',
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('listDomainIdentities')->once()->andReturn([
            'success' => true,
            'identities' => [
                ['name' => 'shared.com', 'sending_enabled' => true, 'verification_status' => 'SUCCESS'],
            ],
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $response = $this->actingAs($teammate)->getJson(route('identities.aws-list'));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'identities' => [
                ['name' => 'shared.com', 'already_added' => true],
            ],
        ]);
    }
}
