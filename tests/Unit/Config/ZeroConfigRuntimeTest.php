<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Docker image starts with no variables. Each config file must derive a
 * working value from APP_URL, from APP_DOMAIN, or from a default. An install
 * that sets its own values, for example the production .env of the owner,
 * must get the same config as before.
 */
class ZeroConfigRuntimeTest extends TestCase
{
    private const KEYS = [
        'APP_URL', 'APP_DOMAIN', 'APP_KEY', 'APP_KEY_FILE', 'TRUSTED_PROXIES',
        'SESSION_SECURE_COOKIE', 'QUEUE_CONNECTION', 'SES_SENDING_LIMITER',
        'DB_BUSY_TIMEOUT', 'DB_JOURNAL_MODE', 'DB_SYNCHRONOUS', 'DB_TRANSACTION_MODE',
        'DB_QUEUE_RETRY_AFTER',
    ];

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $this->setEnv($key, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $server, $putenv]) {
            $this->setEnv($key, $env ?? $server ?? ($putenv === false ? null : $putenv));
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: string}>
     */
    public static function appUrlCases(): array
    {
        return [
            'no setting' => [null, null, 'http://localhost:8080'],
            'domain only' => [null, 'mail.example.com', 'https://mail.example.com'],
            'domain in upper case' => [null, ' Mail.Example.com ', 'https://mail.example.com'],
            'url only' => ['https://news.example.com', null, 'https://news.example.com'],
            'url wins over domain' => ['http://localhost:8000', 'mail.example.com', 'http://localhost:8000'],
        ];
    }

    #[DataProvider('appUrlCases')]
    public function test_the_app_url_comes_from_app_url_then_app_domain_then_the_default(?string $url, ?string $domain, string $expected): void
    {
        $this->setEnv('APP_URL', $url);
        $this->setEnv('APP_DOMAIN', $domain);

        $this->assertSame($expected, $this->configFile('app')['url']);
    }

    public function test_a_domain_makes_the_session_cookie_secure(): void
    {
        $this->setEnv('APP_DOMAIN', 'mail.example.com');

        $this->assertTrue($this->configFile('session')['secure']);
    }

    public function test_an_http_url_gives_a_cookie_that_is_not_secure(): void
    {
        $this->assertFalse($this->configFile('session')['secure']);
    }

    public function test_the_secure_cookie_setting_wins_over_the_url(): void
    {
        $this->setEnv('APP_URL', 'http://localhost:8000');
        $this->setEnv('SESSION_SECURE_COOKIE', 'true');

        $this->assertTrue($this->configFile('session')['secure']);

        $this->setEnv('APP_URL', 'https://news.example.com');
        $this->setEnv('SESSION_SECURE_COOKIE', 'false');

        $this->assertFalse($this->configFile('session')['secure']);
    }

    public function test_a_domain_trusts_the_caddy_of_the_container_and_the_cloudflare_ranges(): void
    {
        $this->setEnv('APP_DOMAIN', 'mail.example.com');

        $config = $this->configFile('trustedproxies');

        $this->assertContains('127.0.0.1', $config['proxies']);
        $this->assertContains('173.245.48.0/20', $config['proxies']);
    }

    public function test_trusted_proxies_win_over_the_domain_default(): void
    {
        $this->setEnv('APP_DOMAIN', 'mail.example.com');
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.1');

        $this->assertSame(['10.0.0.1'], $this->configFile('trustedproxies')['proxies']);
    }

    public function test_without_a_domain_the_proxies_are_the_cloudflare_ranges_only(): void
    {
        $config = $this->configFile('trustedproxies');

        $this->assertSame($config['cloudflare'], $config['proxies']);
    }

    public function test_the_app_key_comes_from_the_key_file_when_app_key_is_empty(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'app_key');
        file_put_contents($file, 'base64:'.base64_encode(str_repeat('k', 32))."\n");

        try {
            $this->setEnv('APP_KEY_FILE', $file);

            $this->assertSame('base64:'.base64_encode(str_repeat('k', 32)), $this->configFile('app')['key']);

            $this->setEnv('APP_KEY', 'base64:'.base64_encode(str_repeat('e', 32)));

            $this->assertSame('base64:'.base64_encode(str_repeat('e', 32)), $this->configFile('app')['key']);
        } finally {
            unlink($file);
        }
    }

    public function test_a_missing_key_file_gives_no_key(): void
    {
        $this->setEnv('APP_KEY_FILE', sys_get_temp_dir().'/byomailer-no-such-key-file');

        $this->assertNull($this->configFile('app')['key']);
    }

    public function test_sqlite_uses_wal_a_busy_timeout_and_immediate_transactions(): void
    {
        $sqlite = $this->configFile('database')['connections']['sqlite'];

        $this->assertSame(5000, $sqlite['busy_timeout']);
        $this->assertSame('wal', $sqlite['journal_mode']);
        $this->assertSame('normal', $sqlite['synchronous']);
        $this->assertSame('IMMEDIATE', $sqlite['transaction_mode']);
    }

    public function test_the_database_queue_gives_a_job_more_time_than_the_longest_job_timeout(): void
    {
        // The newsletter supervisor of Horizon lets a job run for 300 seconds.
        $retryAfter = $this->configFile('queue')['connections']['database']['retry_after'];
        $longest = max(array_column($this->configFile('horizon')['defaults'], 'timeout'));

        $this->assertGreaterThan($longest, $retryAfter);
    }

    public function test_the_send_limiter_uses_redis_only_with_the_redis_queue(): void
    {
        $this->setEnv('QUEUE_CONNECTION', 'redis');
        $this->assertSame('redis', $this->configFile('queue')['ses_sending_limiter']);

        foreach (['database', 'sync', null] as $queue) {
            $this->setEnv('QUEUE_CONNECTION', $queue);
            $this->assertSame('cache', $this->configFile('queue')['ses_sending_limiter'], "The queue {$queue} used Redis.");
        }

        $this->setEnv('SES_SENDING_LIMITER', 'redis');
        $this->assertSame('redis', $this->configFile('queue')['ses_sending_limiter']);
    }

    public function test_composer_does_not_discover_horizon(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertContains('laravel/horizon', $composer['extra']['laravel']['dont-discover']);
    }

    /**
     * @return array<string, mixed>
     */
    private function configFile(string $name): array
    {
        return require base_path("config/{$name}.php");
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}
