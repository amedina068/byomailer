<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\AwsCredentialValidator;
use App\Services\SesTenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BackfillSesTenantsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockTenantService(): SesTenantService
    {
        $mock = Mockery::mock(SesTenantService::class);
        $mock->shouldReceive('provisionTenant')->andReturn(['success' => true])->byDefault();
        $mock->shouldReceive('associateIdentityWithTenant')->andReturn(['success' => true])->byDefault();
        $this->app->instance(SesTenantService::class, $mock);

        return $mock;
    }

    private function mockCredentialValidator(?string $accountId = '123456789012'): AwsCredentialValidator
    {
        $mock = Mockery::mock(AwsCredentialValidator::class);
        $mock->shouldReceive('fetchAccountId')->andReturn($accountId)->byDefault();
        $this->app->instance(AwsCredentialValidator::class, $mock);

        return $mock;
    }

    public function test_it_reports_no_credentials_when_none_exist(): void
    {
        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('No SES credentials without tenants found.')
            ->assertSuccessful();
    }

    public function test_it_skips_credentials_that_already_have_tenant(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->withTenant()->create();

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('No SES credentials without tenants found.')
            ->assertSuccessful();
    }

    public function test_it_creates_tenant_for_credential_without_one(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => '123456789012',
        ]);

        $tenantService = $this->mockTenantService();
        $tenantService->shouldReceive('provisionTenant')
            ->with('byomailer', $credential->access_key, $credential->secret_key, $credential->region, null)
            ->once()
            ->andReturn(['success' => true]);

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('Created: 1, Failed: 0')
            ->assertSuccessful();

        $credential->refresh();
        $this->assertSame('byomailer', $credential->tenant_name);
    }

    public function test_it_associates_identities_with_tenant(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => '123456789012',
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'domain' => 'example.com',
            'tenant_associated_at' => null,
        ]);

        $tenantService = $this->mockTenantService();
        $tenantService->shouldReceive('associateIdentityWithTenant')
            ->with(
                'byomailer',
                'us-east-1',
                '123456789012',
                'example.com',
                $identity->configuration_set_name,
                $credential->access_key,
                $credential->secret_key,
                null,
            )
            ->once()
            ->andReturn(['success' => true]);

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('Associated identity example.com with tenant')
            ->assertSuccessful();

        $identity->refresh();
        $this->assertNotNull($identity->tenant_associated_at);
    }

    public function test_it_skips_identities_without_configuration_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => '123456789012',
        ]);
        EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => null,
            'tenant_associated_at' => null,
        ]);

        $this->mockTenantService();

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('Created: 1, Failed: 0')
            ->assertSuccessful();
    }

    public function test_it_skips_identities_already_associated(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => '123456789012',
        ]);
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'tenant_associated_at' => now(),
        ]);

        $tenantService = $this->mockTenantService();
        $tenantService->shouldReceive('associateIdentityWithTenant')->never();

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('Created: 1, Failed: 0')
            ->assertSuccessful();
    }

    public function test_it_handles_tenant_creation_failure(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => '123456789012',
        ]);

        $tenantService = $this->mockTenantService();
        $tenantService->shouldReceive('provisionTenant')
            ->once()
            ->andReturn(['success' => false, 'error' => 'Access denied']);

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('Failed to create tenant')
            ->expectsOutputToContain('Created: 0, Failed: 1')
            ->assertSuccessful();

        $credential->refresh();
        $this->assertNull($credential->tenant_name);
    }

    public function test_dry_run_does_not_make_changes(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => '123456789012',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'tenant_associated_at' => null,
        ]);

        $tenantService = $this->mockTenantService();
        $tenantService->shouldReceive('provisionTenant')->never();
        $tenantService->shouldReceive('associateIdentityWithTenant')->never();

        $this->artisan('ses:backfill-tenants', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $credential->refresh();
        $identity->refresh();
        $this->assertNull($credential->tenant_name);
        $this->assertNull($identity->tenant_associated_at);
    }

    public function test_it_handles_identity_association_failure(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => '123456789012',
            'region' => 'us-east-1',
        ]);
        $identity = EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'domain' => 'example.com',
            'tenant_associated_at' => null,
        ]);

        $tenantService = $this->mockTenantService();
        $tenantService->shouldReceive('associateIdentityWithTenant')
            ->once()
            ->andReturn(['success' => false, 'error' => 'Not found']);

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('Failed to associate identity example.com')
            ->assertSuccessful();

        $identity->refresh();
        $this->assertNull($identity->tenant_associated_at);
    }

    public function test_it_skips_credential_when_account_id_fetch_fails(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => null,
        ]);

        $this->mockTenantService();
        $this->mockCredentialValidator(null);

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('Failed to fetch account ID, skipping')
            ->expectsOutputToContain('Created: 0, Failed: 1')
            ->assertSuccessful();

        $credential->refresh();
        $this->assertNull($credential->aws_account_id);
        $this->assertNull($credential->tenant_name);
    }

    public function test_it_fetches_account_id_when_missing(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'tenant_name' => null,
            'aws_account_id' => null,
        ]);

        $this->mockTenantService();

        $validator = $this->mockCredentialValidator('999888777666');
        $validator->shouldReceive('fetchAccountId')
            ->with($credential->access_key, $credential->secret_key, $credential->region, null)
            ->once()
            ->andReturn('999888777666');

        $this->artisan('ses:backfill-tenants')
            ->expectsOutputToContain('Fetching AWS account ID')
            ->expectsOutputToContain('Created: 1, Failed: 0')
            ->assertSuccessful();

        $credential->refresh();
        $this->assertSame('999888777666', $credential->aws_account_id);
        $this->assertSame('byomailer', $credential->tenant_name);
    }
}
