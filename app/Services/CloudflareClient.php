<?php

namespace App\Services;

use App\Exceptions\CloudflareException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin adapter over the Cloudflare for SaaS "custom hostnames" API.
 *
 * Lets a customer point their own subdomain (e.g. mail.example.com) at our
 * fallback origin; Cloudflare issues and renews the TLS certificate for it.
 */
class CloudflareClient
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private ?string $apiToken = null,
        private ?string $zoneId = null,
        private ?string $originServer = null,
        private ?string $originSni = null,
    ) {
        $this->apiToken ??= config('services.cloudflare.api_token');
        $this->zoneId ??= config('services.cloudflare.zone_id');
        $this->originServer ??= config('services.cloudflare.origin_server');
        $this->originSni ??= config('services.cloudflare.origin_sni');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiToken) && filled($this->zoneId);
    }

    /**
     * Register a custom hostname. HTTP DCV is validated at the Cloudflare edge
     * once the customer's CNAME resolves — no origin involvement.
     *
     * @return array{id: string, hostname: string, status: string, ssl_status: string}
     */
    public function createCustomHostname(string $hostname): array
    {
        $response = $this->client()->post($this->zoneUrl(), [
            'hostname' => $hostname,
            'ssl' => [
                'method' => 'http',
                'type' => 'dv',
            ],
            ...$this->originPayload(),
        ]);

        return $this->parseHostname($response);
    }

    /**
     * Pin an existing custom hostname to the configured origin server / SNI.
     * Used to backfill hostnames registered before the origin was pinned.
     *
     * @return array{id: string, hostname: string, status: string, ssl_status: string}
     */
    public function syncCustomHostnameOrigin(string $id): array
    {
        $response = $this->client()->patch($this->zoneUrl().'/'.$id, $this->originPayload());

        return $this->parseHostname($response);
    }

    public function hasPinnedOrigin(): bool
    {
        return filled($this->originSni);
    }

    /**
     * Cloudflare connects to the origin with SNI set to the requested hostname
     * unless pinned. Our origin certificate only covers the fallback origin
     * hostname, so an unpinned custom hostname fails Full (strict) with a 526.
     *
     * @return array{custom_origin_server?: string, custom_origin_sni?: string}
     */
    private function originPayload(): array
    {
        return array_filter([
            'custom_origin_server' => $this->originServer,
            'custom_origin_sni' => $this->originSni,
        ], fn (?string $value): bool => filled($value));
    }

    /**
     * @return array{id: string, hostname: string, status: string, ssl_status: string}
     */
    public function getCustomHostname(string $id): array
    {
        $response = $this->client()->get($this->zoneUrl().'/'.$id);

        return $this->parseHostname($response);
    }

    /**
     * Delete a custom hostname. Idempotent: a hostname that is already gone
     * (404) is treated as success so callers can safely retry.
     */
    public function deleteCustomHostname(string $id): void
    {
        $response = $this->client()->delete($this->zoneUrl().'/'.$id);

        if ($response->status() === 404) {
            return;
        }

        $this->guard($response);
    }

    /**
     * @return array{id: string, hostname: string, status: string, ssl_status: string}
     */
    private function parseHostname(Response $response): array
    {
        $this->guard($response);

        $result = $response->json('result', []);

        return [
            'id' => $result['id'] ?? '',
            'hostname' => $result['hostname'] ?? '',
            'status' => $result['status'] ?? 'pending',
            'ssl_status' => $result['ssl']['status'] ?? 'pending_validation',
        ];
    }

    private function guard(Response $response): void
    {
        if ($response->successful() && $response->json('success') === true) {
            return;
        }

        $message = $response->json('errors.0.message')
            ?? 'Cloudflare request failed with status '.$response->status();

        throw new CloudflareException($message);
    }

    private function zoneUrl(): string
    {
        return self::BASE_URL.'/zones/'.$this->zoneId.'/custom_hostnames';
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->apiToken)->acceptJson();
    }
}
