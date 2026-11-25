<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustsCloudflareProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__ip-probe', fn (Request $request): string => $request->ip());
    }

    public function test_resolves_real_visitor_ip_when_request_comes_from_cloudflare(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->get('/__ip-probe', ['X-Forwarded-For' => '203.0.113.7'])
            ->assertOk()
            ->assertSee('203.0.113.7');
    }

    public function test_ignores_forwarded_ip_when_request_does_not_come_from_cloudflare(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->get('/__ip-probe', ['X-Forwarded-For' => '203.0.113.7'])
            ->assertOk()
            ->assertSee('8.8.8.8');
    }

    public function test_trusts_the_proxies_of_the_config_at_request_time(): void
    {
        config(['trustedproxies.proxies' => ['10.0.0.0/8']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->get('/__ip-probe', ['X-Forwarded-For' => '203.0.113.7'])
            ->assertOk()
            ->assertSee('203.0.113.7');

        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->get('/__ip-probe', ['X-Forwarded-For' => '203.0.113.7'])
            ->assertOk()
            ->assertSee('173.245.48.10');
    }

    public function test_default_proxies_are_the_cloudflare_ranges(): void
    {
        $config = $this->loadConfigWithEnv(null);

        $this->assertSame($config['cloudflare'], $config['proxies']);
        $this->assertContains('173.245.48.0/20', $config['proxies']);
    }

    public function test_trusted_proxies_setting_replaces_the_default(): void
    {
        $config = $this->loadConfigWithEnv(' 10.0.0.0/8, 192.168.1.1 ,');

        $this->assertSame(['10.0.0.0/8', '192.168.1.1'], $config['proxies']);
    }

    public function test_env_example_documents_the_proxies_and_secure_cookies(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^SESSION_SECURE_COOKIE=true$/m', $example);
        $this->assertMatchesRegularExpression('/^# TRUSTED_PROXIES=/m', $example);
    }

    /**
     * @return array{cloudflare: list<string>, proxies: list<string>}
     */
    private function loadConfigWithEnv(?string $value): array
    {
        $previous = getenv('TRUSTED_PROXIES');

        if ($value === null) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        } else {
            putenv('TRUSTED_PROXIES='.$value);
            $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;
        }

        try {
            return require config_path('trustedproxies.php');
        } finally {
            putenv($previous === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES='.$previous);
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }
    }
}
