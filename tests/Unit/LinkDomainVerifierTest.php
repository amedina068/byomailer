<?php

namespace Tests\Unit;

use App\Exceptions\CloudflareException;
use App\Models\EmailIdentity;
use App\Services\CloudflareClient;
use App\Services\LinkDomainProbe;
use App\Services\LinkDomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LinkDomainVerifierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{healthy: bool, reason?: string}|null  $result  null when the probe must not run
     */
    private function probe(?array $result = ['healthy' => true]): LinkDomainProbe
    {
        $probe = Mockery::mock(LinkDomainProbe::class);

        if ($result === null) {
            $probe->shouldNotReceive('check');
        } else {
            $probe->shouldReceive('check')->once()->with('mail.example.com')->andReturn($result);
        }

        return $probe;
    }

    public function test_verifies_and_marks_domain_when_cloudflare_hostname_active(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com', verified: false)->create();

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldReceive('getCustomHostname')
            ->once()
            ->with($identity->cf_custom_hostname_id)
            ->andReturn(['id' => 'h1', 'hostname' => 'mail.example.com', 'status' => 'active', 'ssl_status' => 'active']);

        $result = (new LinkDomainVerifier($client, $this->probe()))->verify($identity);

        $this->assertTrue($result['verified']);
        $this->assertNotNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_not_verified_when_ssl_still_pending(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com', verified: false)->create();

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldReceive('getCustomHostname')
            ->andReturn(['id' => 'h1', 'hostname' => 'mail.example.com', 'status' => 'active', 'ssl_status' => 'pending_validation']);

        $result = (new LinkDomainVerifier($client, $this->probe(null)))->verify($identity);

        $this->assertFalse($result['verified']);
        $this->assertNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_returns_error_when_no_link_domain_configured(): void
    {
        $identity = EmailIdentity::factory()->create(['custom_link_domain' => null]);

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldNotReceive('getCustomHostname');

        $result = (new LinkDomainVerifier($client, $this->probe(null)))->verify($identity);

        $this->assertFalse($result['verified']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_already_verified_domain_short_circuits(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com')->create();

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldNotReceive('getCustomHostname');

        $result = (new LinkDomainVerifier($client, $this->probe(null)))->verify($identity);

        $this->assertTrue($result['verified']);
    }

    public function test_surfaces_cloudflare_error(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com', verified: false)->create();

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldReceive('getCustomHostname')
            ->andThrow(new CloudflareException('hostname not found'));

        $result = (new LinkDomainVerifier($client, $this->probe(null)))->verify($identity);

        $this->assertFalse($result['verified']);
        $this->assertSame('hostname not found', $result['error']);
    }

    public function test_not_verified_when_the_domain_does_not_reach_the_app(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com', verified: false)->create();

        $client = Mockery::mock(CloudflareClient::class);
        $client->shouldReceive('getCustomHostname')
            ->andReturn(['id' => 'h1', 'hostname' => 'mail.example.com', 'status' => 'active', 'ssl_status' => 'active']);

        $reason = 'https://mail.example.com/up redirects to https://byomailer.example/up. The web server does not send this host to the app.';

        $result = (new LinkDomainVerifier($client, $this->probe(['healthy' => false, 'reason' => $reason])))->verify($identity);

        $this->assertSame(['verified' => false, 'error' => $reason], $result);
        $this->assertNull($identity->fresh()->custom_link_domain_verified_at);
    }
}
