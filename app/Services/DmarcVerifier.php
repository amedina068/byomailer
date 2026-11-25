<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Reads the DMARC policy a sending domain actually publishes.
 *
 * SES verifies DKIM and the custom MAIL FROM, but it never looks at `_dmarc`.
 * Without this check an identity reaches "Verified" with no DMARC record at
 * all, which fails the Gmail, Yahoo and Microsoft bulk-sender requirements and
 * is only discovered once mail starts landing in spam.
 */
class DmarcVerifier
{
    public function __construct(private DnsResolver $dns) {}

    /**
     * @return array{published: bool, policy: ?string, record: ?string}
     */
    public function check(string $domain): array
    {
        $domain = trim($domain, " \t\n\r\0\x0B.");

        if ($domain === '') {
            return ['published' => false, 'policy' => null, 'record' => null];
        }

        foreach ($this->dns->txt("_dmarc.{$domain}") as $value) {
            $value = trim($value);

            if (! Str::startsWith(Str::lower($value), 'v=dmarc1')) {
                continue;
            }

            return [
                'published' => true,
                'policy' => $this->extractPolicy($value),
                'record' => $value,
            ];
        }

        return ['published' => false, 'policy' => null, 'record' => null];
    }

    /**
     * The `p=` tag, lowercased. Null when the record omits it, which makes the
     * record invalid — treated as "published but no usable policy".
     */
    private function extractPolicy(string $record): ?string
    {
        foreach (explode(';', $record) as $tag) {
            [$name, $value] = array_pad(explode('=', trim($tag), 2), 2, null);

            if (Str::lower(trim((string) $name)) === 'p' && $value !== null) {
                return Str::lower(trim($value)) ?: null;
            }
        }

        return null;
    }
}
