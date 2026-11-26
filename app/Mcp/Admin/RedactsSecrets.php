<?php

namespace App\Mcp\Admin;

/**
 * Removes secrets from text that an instance-admin tool returns.
 *
 * It removes the values of the secret config keys (APP_KEY, the database,
 * Redis and mail passwords, the AWS keys of the app and of the SES event
 * queue), bearer tokens, Sanctum token values, AWS access key ids, private
 * keys, and the values of `password`, `token`, `secret`, `signature` and
 * `key` parameters.
 */
trait RedactsSecrets
{
    protected function redact(string $text): string
    {
        $secrets = array_filter(array_map('strval', [
            config('app.key'),
            config('database.connections.mysql.password'),
            config('database.connections.mariadb.password'),
            config('database.connections.pgsql.password'),
            config('database.redis.default.password'),
            config('mail.mailers.smtp.password'),
            config('services.ses.secret'),
            config('services.sqs_ses_events.secret'),
            config('filesystems.disks.s3.secret'),
        ]), fn (string $value): bool => strlen($value) >= 6);

        // The patterns run first. A secret value can be the name of a
        // parameter, for example the database password `password`. A replace
        // of that value first removes the name, and the pattern then misses
        // the parameter value.
        $text = (string) preg_replace([
            '/(Bearer\s+)[A-Za-z0-9._~+\/=|-]+/i',
            '/\b\d+\|[A-Za-z0-9]{20,}/',
            '/base64:[A-Za-z0-9+\/=]{20,}/',
            '/\b(AKIA|ASIA)[A-Z0-9]{16}\b/',
            '/((?:password|passwd|pwd|token|secret|signature|api_?key|key)["\']?\s*[=:]\s*["\']?)[^\s"\'&,;]+/i',
            '/(-----BEGIN [A-Z ]+-----)[\s\S]*?(-----END [A-Z ]+-----)/',
        ], [
            '$1[redacted]',
            '[redacted]',
            '[redacted]',
            '[redacted]',
            '$1[redacted]',
            '$1[redacted]$2',
        ], $text);

        return $secrets === [] ? $text : str_replace($secrets, '[redacted]', $text);
    }
}
