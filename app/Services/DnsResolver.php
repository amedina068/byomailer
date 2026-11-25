<?php

namespace App\Services;

/**
 * Thin wrapper around the system resolver so DNS lookups can be replaced in
 * tests. Failures return an empty list: a lookup that cannot complete is
 * treated as "not published yet", never as an error that stops a command.
 */
class DnsResolver
{
    /**
     * Fetch the TXT records published at a name.
     *
     * @return array<int, string>
     */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            // Long TXT values arrive split into `entries`; `txt` holds only the
            // first chunk, so rejoin the chunks when they are present.
            $value = isset($record['entries']) && is_array($record['entries'])
                ? implode('', $record['entries'])
                : (string) ($record['txt'] ?? '');

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
