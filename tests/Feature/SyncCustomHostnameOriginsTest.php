<?php

namespace Tests\Feature;

use App\Models\EmailIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCustomHostnameOriginsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cloudflare.api_token' => 'test-token',
            'services.cloudflare.zone_id' => 'zone-123',
            'services.cloudflare.origin_server' => 'link.byomailer.com',
            'services.cloudflare.origin_sni' => 'link.byomailer.com',
        ]);
    }

    private function fakeCloudflare(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-hostname-1',
                    'hostname' => 'mail.example.com',
                    'status' => 'active',
                    'ssl' => ['status' => 'active'],
                ],
            ]),
        ]);
    }

    public function test_pins_origin_on_registered_hostnames(): void
    {
        $this->fakeCloudflare();

        EmailIdentity::factory()->create([
            'custom_link_domain' => 'mail.example.com',
            'cf_custom_hostname_id' => 'cf-hostname-1',
        ]);

        $this->artisan('email-identities:sync-hostname-origins')->assertSuccessful();

        Http::assertSent(function ($request): bool {
            return $request->method() === 'PATCH'
                && $request['custom_origin_server'] === 'link.byomailer.com'
                && $request['custom_origin_sni'] === 'link.byomailer.com';
        });
    }

    public function test_skips_identities_without_a_registered_hostname(): void
    {
        $this->fakeCloudflare();

        EmailIdentity::factory()->create([
            'custom_link_domain' => 'mail.example.com',
            'cf_custom_hostname_id' => null,
        ]);

        $this->artisan('email-identities:sync-hostname-origins')
            ->expectsOutputToContain('No registered custom hostnames to sync.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_fails_when_no_origin_sni_configured(): void
    {
        Http::fake();

        config(['services.cloudflare.origin_sni' => null]);

        $this->artisan('email-identities:sync-hostname-origins')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_reports_failure_when_cloudflare_rejects_the_patch(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'custom origin not allowed on this plan']],
            ], 400),
        ]);

        EmailIdentity::factory()->create([
            'custom_link_domain' => 'mail.example.com',
            'cf_custom_hostname_id' => 'cf-hostname-1',
        ]);

        $this->artisan('email-identities:sync-hostname-origins')
            ->expectsOutputToContain('custom origin not allowed on this plan')
            ->assertFailed();
    }
}
