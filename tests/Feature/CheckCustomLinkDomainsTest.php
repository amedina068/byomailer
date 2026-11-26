<?php

namespace Tests\Feature;

use App\Models\EmailIdentity;
use App\Services\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckCustomLinkDomainsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host): string => '93.184.216.34');
        $this->app->instance(SsrfGuard::class, $guard);
    }

    private function fakeCloudflare(string $status, string $sslStatus, int $probeStatus = 200): void
    {
        Http::fake([
            'mail.example.com/*' => Http::response('', $probeStatus, $probeStatus === 301 ? ['Location' => 'https://byomailer.example/up'] : []),
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-hostname-1',
                    'hostname' => 'mail.example.com',
                    'status' => $status,
                    'ssl' => ['status' => $sslStatus],
                ],
            ]),
        ]);
    }

    public function test_activates_pending_link_domain_once_cloudflare_reports_active(): void
    {
        $this->fakeCloudflare('active', 'active');

        $identity = EmailIdentity::factory()->create([
            'custom_link_domain' => 'mail.example.com',
            'custom_link_domain_verified_at' => null,
            'cf_custom_hostname_id' => 'cf-hostname-1',
        ]);

        $this->artisan('email-identities:check-link-domain')->assertSuccessful();

        $this->assertNotNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_leaves_link_domain_unverified_when_it_does_not_reach_the_app(): void
    {
        $this->fakeCloudflare('active', 'active', probeStatus: 301);

        $identity = EmailIdentity::factory()->create([
            'custom_link_domain' => 'mail.example.com',
            'custom_link_domain_verified_at' => null,
            'cf_custom_hostname_id' => 'cf-hostname-1',
        ]);

        $this->artisan('email-identities:check-link-domain')
            ->expectsOutputToContain('redirects to https://byomailer.example/up')
            ->assertSuccessful();

        $this->assertNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_leaves_link_domain_unverified_while_cloudflare_still_pending(): void
    {
        $this->fakeCloudflare('pending', 'pending_validation');

        $identity = EmailIdentity::factory()->create([
            'custom_link_domain' => 'mail.example.com',
            'custom_link_domain_verified_at' => null,
            'cf_custom_hostname_id' => 'cf-hostname-1',
        ]);

        $this->artisan('email-identities:check-link-domain')->assertSuccessful();

        $this->assertNull($identity->fresh()->custom_link_domain_verified_at);
    }

    public function test_clears_stale_ui_check_cache_when_activating(): void
    {
        $this->fakeCloudflare('active', 'active');

        $identity = EmailIdentity::factory()->create([
            'custom_link_domain' => 'mail.example.com',
            'custom_link_domain_verified_at' => null,
            'cf_custom_hostname_id' => 'cf-hostname-1',
        ]);

        Cache::put("link-domain-check:{$identity->id}", ['verified' => false], 300);

        $this->artisan('email-identities:check-link-domain')->assertSuccessful();

        $this->assertNull(Cache::get("link-domain-check:{$identity->id}"));
    }

    public function test_skips_when_no_unverified_link_domains(): void
    {
        EmailIdentity::factory()->withLinkDomain('mail.example.com')->create();

        $this->artisan('email-identities:check-link-domain')
            ->expectsOutputToContain('No unverified custom link domains to check.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
