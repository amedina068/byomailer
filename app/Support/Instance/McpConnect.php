<?php

namespace App\Support\Instance;

use App\Mcp\Servers\InstanceAdminServer;

/**
 * The commands and the config that connect an MCP client to `/mcp`.
 *
 * `byomailer:install --with-token` and `byomailer:mcp:connect` print these
 * lines. The Claude, Codex and Cursor lines read the token from the
 * `BYOMAILER_TOKEN` environment variable, so the token is not in the command
 * text or in the shell history. When the token is known, the `env` line
 * exports it. Only the generic `json` config carries the token itself.
 */
class McpConnect
{
    public const SERVER_NAME = 'byomailer';

    public const TOKEN_ENV = 'BYOMAILER_TOKEN';

    public const CLIENTS = ['claude', 'codex', 'cursor', 'json'];

    public static function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/mcp';
    }

    /**
     * The `connect` object of `byomailer:install --with-token`.
     *
     * @return array{mcp_url: string, token_env: string, env: string|null, claude: string, codex: string}
     */
    public static function summary(?string $token): array
    {
        return [
            'mcp_url' => self::url(),
            'token_env' => self::TOKEN_ENV,
            'env' => self::envLine($token),
            'claude' => self::claude(),
            'codex' => self::codex(),
        ];
    }

    /**
     * The connect data for one client.
     *
     * @return array<string, mixed>
     */
    public static function forClient(string $client, ?string $token): array
    {
        $data = [
            'client' => $client,
            'mcp_url' => self::url(),
            'token_env' => self::TOKEN_ENV,
            'env' => self::envLine($token),
        ];

        return $data + match ($client) {
            'claude' => ['command' => self::claude()],
            'codex' => ['command' => self::codex(), 'config_toml' => self::codexToml()],
            'cursor' => [
                'path' => '~/.cursor/mcp.json',
                'config' => ['mcpServers' => [self::SERVER_NAME => [
                    'url' => self::url(),
                    'headers' => ['Authorization' => 'Bearer ${env:'.self::TOKEN_ENV.'}'],
                ]]],
            ],
            // json: a generic `mcpServers` entry for any client.
            default => [
                'config' => ['mcpServers' => [self::SERVER_NAME => [
                    'type' => 'http',
                    'url' => self::url(),
                    'headers' => ['Authorization' => 'Bearer '.($token ?? '${'.self::TOKEN_ENV.'}')],
                ]]],
            ],
        } + ['admin' => self::admin()];
    }

    public static function claude(): string
    {
        return 'claude mcp add --transport http '.self::SERVER_NAME.' '.self::url()
            .' --header "Authorization: Bearer ${'.self::TOKEN_ENV.'}"';
    }

    public static function codex(): string
    {
        return 'codex mcp add '.self::SERVER_NAME.' --url '.self::url().' --bearer-token-env-var '.self::TOKEN_ENV;
    }

    public static function codexToml(): string
    {
        return '[mcp_servers.'.self::SERVER_NAME."]\n"
            .'url = "'.self::url()."\"\n"
            .'bearer_token_env_var = "'.self::TOKEN_ENV."\"\n";
    }

    /**
     * The instance-admin server runs over stdio on the server itself.
     *
     * @return array{stdio: string, claude: string}
     */
    public static function admin(): array
    {
        $command = 'docker exec -i <container> php artisan mcp:start '.InstanceAdminServer::HANDLE;

        return [
            'stdio' => "ssh <host> {$command}",
            'claude' => 'claude mcp add '.InstanceAdminServer::HANDLE." -- ssh <host> {$command}",
        ];
    }

    private static function envLine(?string $token): ?string
    {
        return $token === null ? null : 'export '.self::TOKEN_ENV.'='.escapeshellarg($token);
    }
}
