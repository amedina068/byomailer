<?php

namespace Tests\Unit;

use App\Exceptions\CloudflareException;
use App\Services\CloudflareClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareClientTest extends TestCase
{
    private function client(): CloudflareClient
    {
        return new CloudflareClient('test-token', 'zone-123', 'link.byomailer.com', 'link.byomailer.com');
    }

    public function test_is_configured_reflects_credentials(): void
    {
        $this->assertTrue($this->client()->isConfigured());
        $this->assertFalse((new CloudflareClient(null, null))->isConfigured());
    }

    public function test_create_custom_hostname_posts_to_cloudflare(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-hostname-1',
                    'hostname' => 'mail.example.com',
                    'status' => 'pending',
                    'ssl' => ['status' => 'pending_validation'],
                ],
            ]),
        ]);

        $result = $this->client()->createCustomHostname('mail.example.com');

        $this->assertSame('cf-hostname-1', $result['id']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame('pending_validation', $result['ssl_status']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-123/custom_hostnames'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['hostname'] === 'mail.example.com'
                && $request['ssl']['method'] === 'http'
                && $request['custom_origin_server'] === 'link.byomailer.com'
                && $request['custom_origin_sni'] === 'link.byomailer.com';
        });
    }

    public function test_create_custom_hostname_omits_origin_when_not_configured(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-hostname-1',
                    'hostname' => 'mail.example.com',
                    'status' => 'pending',
                    'ssl' => ['status' => 'pending_validation'],
                ],
            ]),
        ]);

        config(['services.cloudflare.origin_server' => null, 'services.cloudflare.origin_sni' => null]);

        (new CloudflareClient('test-token', 'zone-123'))->createCustomHostname('mail.example.com');

        Http::assertSent(fn ($request): bool => ! isset($request['custom_origin_server'])
            && ! isset($request['custom_origin_sni']));
    }

    public function test_sync_custom_hostname_origin_patches_origin_fields(): void
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

        $result = $this->client()->syncCustomHostnameOrigin('cf-hostname-1');

        $this->assertSame('active', $result['status']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-123/custom_hostnames/cf-hostname-1'
                && $request['custom_origin_server'] === 'link.byomailer.com'
                && $request['custom_origin_sni'] === 'link.byomailer.com';
        });
    }

    public function test_has_pinned_origin_reflects_configured_sni(): void
    {
        $this->assertTrue($this->client()->hasPinnedOrigin());
        $this->assertFalse((new CloudflareClient('test-token', 'zone-123', null, ''))->hasPinnedOrigin());
    }

    public function test_origin_falls_back_to_configured_values(): void
    {
        config([
            'services.cloudflare.origin_server' => 'fallback.byomailer.com',
            'services.cloudflare.origin_sni' => 'fallback.byomailer.com',
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-hostname-1',
                    'hostname' => 'mail.example.com',
                    'status' => 'pending',
                    'ssl' => ['status' => 'pending_validation'],
                ],
            ]),
        ]);

        (new CloudflareClient('test-token', 'zone-123'))->createCustomHostname('mail.example.com');

        Http::assertSent(fn ($request): bool => $request['custom_origin_sni'] === 'fallback.byomailer.com'
            && $request['custom_origin_server'] === 'fallback.byomailer.com');
    }

    public function test_get_custom_hostname_returns_status(): void
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

        $result = $this->client()->getCustomHostname('cf-hostname-1');

        $this->assertSame('active', $result['status']);
        $this->assertSame('active', $result['ssl_status']);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-123/custom_hostnames/cf-hostname-1');
    }

    public function test_delete_custom_hostname_sends_delete(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'cf-hostname-1']]),
        ]);

        $this->client()->deleteCustomHostname('cf-hostname-1');

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-123/custom_hostnames/cf-hostname-1');
    }

    public function test_delete_is_idempotent_when_hostname_already_gone(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'not found']],
            ], 404),
        ]);

        $this->client()->deleteCustomHostname('cf-gone');

        $this->expectNotToPerformAssertions();
    }

    public function test_throws_when_cloudflare_returns_error(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'hostname already exists']],
            ], 400),
        ]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('hostname already exists');

        $this->client()->createCustomHostname('mail.example.com');
    }
}
