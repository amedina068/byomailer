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

/**
 * SES verifies DKIM and the custom MAIL FROM but never reads `_dmarc`, so an
 * identity could reach "Verified" with no DMARC policy at all — which fails
 * the Gmail, Yahoo and Microsoft bulk-sender requirements.
 */
class DmarcVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_published_policy_marks_the_dmarc_record_verified(): void
    {
        $identity = $this->makeVerifiedIdentity('example.com');
        $this->fakeDns(['_dmarc.example.com' => ['v=DMARC1; p=quarantine; rua=mailto:r@example.com']]);

        $this->artisan('email-identities:refresh-dns-status')->assertExitCode(0);

        $this->assertNotNull($this->dmarcRecord($identity)->verified_at);
    }

    public function test_a_missing_policy_leaves_the_dmarc_record_unverified(): void
    {
        $identity = $this->makeVerifiedIdentity('example.com');
        $this->fakeDns([]);

        $this->artisan('email-identities:refresh-dns-status')
            ->expectsOutputToContain('no DMARC policy published')
            ->assertExitCode(0);

        $this->assertNull($this->dmarcRecord($identity)->verified_at);
    }

    public function test_a_txt_record_that_is_not_dmarc_does_not_count(): void
    {
        $identity = $this->makeVerifiedIdentity('example.com');
        $this->fakeDns(['_dmarc.example.com' => ['some-unrelated-verification-token']]);

        $this->artisan('email-identities:refresh-dns-status')->assertExitCode(0);

        $this->assertNull($this->dmarcRecord($identity)->verified_at);
    }

    public function test_a_policy_of_none_is_accepted_but_reported(): void
    {
        $identity = $this->makeVerifiedIdentity('example.com');
        $this->fakeDns(['_dmarc.example.com' => ['v=DMARC1; p=none;']]);

        $this->artisan('email-identities:refresh-dns-status')
            ->expectsOutputToContain('p=none')
            ->assertExitCode(0);

        $this->assertNotNull($this->dmarcRecord($identity)->verified_at);
    }

    public function test_a_policy_removed_later_clears_the_verification(): void
    {
        $identity = $this->makeVerifiedIdentity('example.com');
        $this->dmarcRecord($identity)->forceFill(['verified_at' => now()->subWeek()])->save();

        $this->fakeDns([]);

        $this->artisan('email-identities:refresh-dns-status')->assertExitCode(0);

        $this->assertNull($this->dmarcRecord($identity)->verified_at);
    }

    public function test_a_recently_confirmed_policy_is_not_re_queried(): void
    {
        $identity = $this->makeVerifiedIdentity('example.com');
        $this->dmarcRecord($identity)->forceFill(['verified_at' => now()->subHour()])->save();

        $resolver = Mockery::mock(DnsResolver::class);
        $resolver->shouldNotReceive('txt');
        $this->app->instance(DnsResolver::class, $resolver);

        $this->artisan('email-identities:refresh-dns-status')
            ->expectsOutput('No pending email identities to check.')
            ->assertExitCode(0);
    }

    /**
     * A verified identity is otherwise skipped by the command. It still has to
     * be re-checked for DMARC, because customers commonly publish the record
     * days after SES has verified the domain.
     */
    private function makeVerifiedIdentity(string $domain): EmailIdentity
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'key',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
        ]);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldNotReceive('getIdentityStatus');
        $this->app->instance(SesDomainIdentityService::class, $service);

        return EmailIdentity::factory()->for($user)->create([
            'domain' => $domain,
            'mail_from_subdomain' => 'mas.'.$domain,
            'identity_status' => IdentityStatus::Verified,
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $records
     */
    private function fakeDns(array $records): void
    {
        $resolver = Mockery::mock(DnsResolver::class);
        $resolver->shouldReceive('txt')
            ->andReturnUsing(fn (string $name): array => $records[$name] ?? []);

        $this->app->instance(DnsResolver::class, $resolver);
    }

    private function dmarcRecord(EmailIdentity $identity): EmailIdentityDnsRecord
    {
        return $identity->dnsRecords()->where('purpose', 'dmarc')->firstOrFail();
    }
}
