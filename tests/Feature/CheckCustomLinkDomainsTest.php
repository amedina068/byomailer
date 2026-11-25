<?php

namespace Tests\Feature;

use App\Models\EmailIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckCustomLinkDomainsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCloudflare(string $status, string $sslStatus): void
    {
        Http::fake([
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
