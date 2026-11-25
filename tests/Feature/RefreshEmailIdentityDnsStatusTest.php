<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\EmailIdentityDnsRecord;
use App\Models\User;
use App\Services\DnsResolver;
use App\Services\SesDomainIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RefreshEmailIdentityDnsStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The command resolves `_dmarc.<domain>`. Stub it so no test reaches
        // the network; DMARC behaviour has its own test.
        $resolver = Mockery::mock(DnsResolver::class);
        $resolver->shouldReceive('txt')->andReturn([]);
        $this->app->instance(DnsResolver::class, $resolver);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_refreshes_pending_email_identities(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'test@example.com',
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

        $this->artisan('email-identities:refresh-dns-status')
            ->expectsOutput('Checking 1 email identities...')
            ->expectsOutput('test@example.com: Verified!')
            ->assertSuccessful();

        $identity->refresh();
        $this->assertEquals(IdentityStatus::Verified, $identity->identity_status);
        $this->assertNotNull($identity->verified_at);
        $this->assertNotNull($identity->last_status_check_at);
    }

    public function test_command_updates_verifying_identities(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'test@example.com',
            'domain' => 'example.com',
            'identity_status' => IdentityStatus::Verifying,
            'verified_at' => null,
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

        $this->artisan('email-identities:refresh-dns-status')
            ->assertSuccessful();

        $identity->refresh();
        $this->assertEquals(IdentityStatus::Verifying, $identity->identity_status);
        $this->assertNull($identity->verified_at);
    }

    /**
     * A verified identity is never re-queried against SES. It is still picked
     * up for the DMARC lookup, because customers often publish `_dmarc` days
     * after SES has verified the domain.
     */
    public function test_command_never_re_queries_ses_for_verified_identities(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        EmailIdentity::factory()->for($user)->create([
            'email' => 'verified@example.com',
            'domain' => 'example.com',
            'identity_status' => IdentityStatus::Verified,
            'verified_at' => now(),
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('getIdentityStatus');
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->artisan('email-identities:refresh-dns-status')->assertSuccessful();
    }

    public function test_command_skips_identities_without_domain(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        EmailIdentity::factory()->for($user)->create([
            'email' => 'legacy@example.com',
            'domain' => null,
            'identity_status' => IdentityStatus::Pending,
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('getIdentityStatus');
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->artisan('email-identities:refresh-dns-status')
            ->expectsOutput('No pending email identities to check.')
            ->assertSuccessful();
    }

    public function test_command_skips_identities_without_credentials(): void
    {
        $user = User::factory()->create();

        EmailIdentity::factory()->for($user)->create([
            'email' => 'test@example.com',
            'domain' => 'example.com',
            'identity_status' => IdentityStatus::Pending,
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('getIdentityStatus');
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->artisan('email-identities:refresh-dns-status')
            ->expectsOutput('Checking 1 email identities...')
            ->expectsOutput('test@example.com: No AWS credentials found, skipping.')
            ->assertSuccessful();
    }

    public function test_command_handles_api_errors(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'email' => 'test@example.com',
            'domain' => 'example.com',
            'identity_status' => IdentityStatus::Pending,
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Access denied',
            ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->artisan('email-identities:refresh-dns-status')
            ->expectsOutput('Checking 1 email identities...')
            ->expectsOutput('test@example.com: Access denied')
            ->assertSuccessful();

        $identity->refresh();
        $this->assertEquals(IdentityStatus::Pending, $identity->identity_status);
    }

    public function test_command_processes_multiple_identities(): void
    {
        $user1 = User::factory()->create();
        EmailCredential::factory()->for($user1)->create([
            'access_key' => 'key1',
            'secret_key' => 'secret1',
            'region' => 'us-east-1',
        ]);

        $user2 = User::factory()->create();
        EmailCredential::factory()->for($user2)->create([
            'access_key' => 'key2',
            'secret_key' => 'secret2',
            'region' => 'eu-west-1',
        ]);

        $identity1 = EmailIdentity::factory()->for($user1)->create([
            'email' => 'user1@example.com',
            'domain' => 'example.com',
            'identity_status' => IdentityStatus::Pending,
        ]);

        $identity2 = EmailIdentity::factory()->for($user2)->create([
            'email' => 'user2@other.com',
            'domain' => 'other.com',
            'identity_status' => IdentityStatus::Verifying,
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->with('example.com', 'key1', 'secret1', 'us-east-1')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'verified',
                'dkim_status' => 'SUCCESS',
                'mail_from_status' => 'SUCCESS',
            ]);
        $service->shouldReceive('getIdentityStatus')
            ->with('other.com', 'key2', 'secret2', 'eu-west-1')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'pending',
                'dkim_status' => 'PENDING',
                'mail_from_status' => 'PENDING',
            ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->artisan('email-identities:refresh-dns-status')
            ->expectsOutput('Checking 2 email identities...')
            ->assertSuccessful();

        $identity1->refresh();
        $identity2->refresh();

        $this->assertEquals(IdentityStatus::Verified, $identity1->identity_status);
        $this->assertEquals(IdentityStatus::Verifying, $identity2->identity_status);
    }

    public function test_command_marks_the_dkim_and_mail_from_records_with_the_ses_status(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'domain' => 'example.com',
            'mail_from_subdomain' => 'mas.example.com',
            'identity_status' => IdentityStatus::Verifying,
            'verified_at' => null,
        ]);
        $dkim = EmailIdentityDnsRecord::factory()->for($identity, 'identity')->create(['type' => 'CNAME', 'purpose' => 'dkim']);
        $mailFromMx = EmailIdentityDnsRecord::factory()->for($identity, 'identity')->create(['type' => 'MX', 'name' => 'mas.example.com', 'purpose' => 'mail_from']);
        $mailFromTxt = EmailIdentityDnsRecord::factory()->for($identity, 'identity')->create(['type' => 'TXT', 'name' => 'mas.example.com', 'purpose' => 'mail_from']);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')->once()->andReturn([
            'success' => true,
            'status' => 'pending',
            'dkim_status' => 'SUCCESS',
            'mail_from_status' => 'PENDING',
        ]);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->artisan('email-identities:refresh-dns-status')->assertSuccessful();

        $this->assertSame(IdentityStatus::Verifying, $identity->fresh()->identity_status);
        $this->assertNotNull($dkim->fresh()->verified_at);
        $this->assertNull($mailFromMx->fresh()->verified_at);
        $this->assertNull($mailFromTxt->fresh()->verified_at);
    }
}
