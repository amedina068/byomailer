<?php

namespace App\Services;

use App\Exceptions\SsrfException;
use Closure;

class SsrfGuard
{
    private ?Closure $dnsResolver = null;

    /**
     * Override DNS resolution (testing). Resolver returns an IP string or false.
     *
     * @param  Closure(string): (string|false)  $resolver
     */
    public function setDnsResolver(Closure $resolver): void
    {
        $this->dnsResolver = $resolver;
    }

    public function isSafe(string $url): bool
    {
        try {
            $this->assertSafe($url);

            return true;
        } catch (SsrfException) {
            return false;
        }
    }

    /**
     * Validate a destination URL and return the resolved IP it is safe to connect to.
     *
     * @throws SsrfException
     */
    public function assertSafe(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            throw new SsrfException('Webhook URL must use https.');
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            throw new SsrfException('Webhook URL is missing a host.');
        }

        // Reject bracketed IPv6 literal hosts outright (e.g. [::1], [::ffff:...]).
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new SsrfException('IPv6 literal hosts are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = $host;
        } else {
            $resolved = $this->resolve($host);

            if ($resolved === false || $resolved === '') {
                throw new SsrfException("Unable to resolve host: {$host}");
            }

            $ip = $resolved;
        }

        if ($this->isBlockedIp($ip)) {
            throw new SsrfException("Webhook URL resolves to a blocked address: {$ip}");
        }

        return $ip;
    }

    /**
     * The cURL options that pin the host of a safe URL to the IP that this
     * guard checked. A second DNS lookup cannot then send the request elsewhere.
     *
     * @return array<int, array<int, string>>
     *
     * @throws SsrfException
     */
    public function pinnedCurlOptions(string $url): array
    {
        $ip = $this->assertSafe($url);
        $parts = parse_url($url);
        $port = $parts['port'] ?? 443;

        return [CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$ip}"]];
    }

    private function resolve(string $host): string|false
    {
        if ($this->dnsResolver) {
            return ($this->dnsResolver)($host);
        }

        $ip = gethostbyname($host);

        return $ip === $host ? false : $ip;
    }

    private function isBlockedIp(string $ip): bool
    {
        // Normalise IPv4-mapped IPv6 (::ffff:a.b.c.d) down to the embedded IPv4.
        $lower = strtolower($ip);

        if (str_starts_with($lower, '::ffff:')) {
            $candidate = substr($ip, 7);

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $candidate;
            } else {
                return true;
            }
        }

        if ($ip === '0.0.0.0') {
            return true;
        }

        // Private + reserved ranges (covers loopback, 10/8, 172.16/12, 192.168/16,
        // 169.254/16 link-local incl. metadata, 240/4 reserved, IPv6 ::1/fc00/fe80).
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // Carrier-grade NAT 100.64.0.0/10 is not covered by the reserved-range flag.
        if ($this->inCidr($ip, '100.64.0.0', 10)) {
            return true;
        }

        return false;
    }

    private function inCidr(string $ip, string $subnet, int $bits): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
