<?php

namespace Tests\Unit;

use App\Exceptions\SsrfException;
use App\Services\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SsrfGuardTest extends TestCase
{
    private function guardResolving(string $ip): SsrfGuard
    {
        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host) => $ip);

        return $guard;
    }

    public function test_rejects_non_https_scheme(): void
    {
        $this->assertFalse($this->guardResolving('93.184.216.34')->isSafe('http://example.com/hook'));
    }

    public function test_allows_public_https_host_and_returns_resolved_ip(): void
    {
        $guard = $this->guardResolving('93.184.216.34');

        $this->assertTrue($guard->isSafe('https://example.com/hook'));
        $this->assertSame('93.184.216.34', $guard->assertSafe('https://example.com/hook'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedLiteralHosts(): array
    {
        return [
            'loopback v4' => ['https://127.0.0.1/hook'],
            'private 10' => ['https://10.0.0.1/hook'],
            'private 192' => ['https://192.168.1.1/hook'],
            'private 172' => ['https://172.16.0.1/hook'],
            'metadata' => ['https://169.254.169.254/hook'],
            'all zeros' => ['https://0.0.0.0/hook'],
            'cgnat' => ['https://100.64.0.1/hook'],
            'reserved 240' => ['https://240.0.0.1/hook'],
            'ipv6 loopback literal' => ['https://[::1]/hook'],
            'ipv6 mapped metadata literal' => ['https://[::ffff:169.254.169.254]/hook'],
        ];
    }

    #[DataProvider('blockedLiteralHosts')]
    public function test_rejects_blocked_literal_hosts(string $url): void
    {
        // resolver returns a public IP, proving the literal-host check (not DNS) does the blocking
        $this->assertFalse($this->guardResolving('93.184.216.34')->isSafe($url));
    }

    public function test_rejects_when_dns_rebinds_to_private_ip(): void
    {
        $guard = $this->guardResolving('10.0.0.5');

        $this->assertFalse($guard->isSafe('https://totally-public-looking.example.com/hook'));
    }

    public function test_rejects_when_dns_resolves_to_mapped_private_ipv6(): void
    {
        $guard = $this->guardResolving('::ffff:192.168.1.1');

        $this->assertFalse($guard->isSafe('https://rebind.example.com/hook'));
    }

    public function test_assert_safe_throws_for_unsafe(): void
    {
        $this->expectException(SsrfException::class);

        $this->guardResolving('10.0.0.1')->assertSafe('https://rebind.example.com/hook');
    }

    public function test_rejects_unresolvable_host(): void
    {
        $guard = new SsrfGuard;
        $guard->setDnsResolver(fn (string $host) => false);

        $this->assertFalse($guard->isSafe('https://nope.example.com/hook'));
    }
}
