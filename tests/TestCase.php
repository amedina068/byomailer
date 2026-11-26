<?php

namespace Tests;

use App\Exceptions\MissingTenantException;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // A test must fake every HTTP call. A real call makes the suite slow and
        // flaky, and it can reach a third-party API with real credentials.
        Http::preventStrayRequests();

        // A catch block that reports the exception must not hide a query with no tenant.
        $this->app->make(ExceptionHandler::class)->reportable(fn (MissingTenantException $exception) => throw $exception);
    }

    /**
     * Create one account, so that the instance is set up. While the users
     * table is empty, /, /login and /register go to the /setup page.
     */
    protected function completeInstanceSetup(): void
    {
        User::factory()->create();
    }

    /**
     * Run the rest of the test as the given client, as a request of one of its
     * users does. Use it for code that production calls inside a tenant. Do not
     * use it for a test of an entry point (a command, a webhook, a cron job):
     * that test proves that the entry point sets the tenant itself.
     */
    protected function actingAsTenant(int $clientId): static
    {
        Context::addHidden(Tenant::CONTEXT_KEY, $clientId);

        return $this;
    }

    /**
     * Sequence mails send through a job on the Redis `ses-sending` limiter.
     * Tests that run the cron on the sync queue lift the limit, so they do
     * not need Redis and are not released by an earlier test.
     */
    protected function withoutSesSendingLimit(): void
    {
        RateLimiter::for('ses-sending', fn (): Limit => Limit::none());
    }

    /**
     * Compare decoded JSON data. The keys of an object can have any order, but
     * the values and the order of a list must be the same.
     *
     * A MySQL JSON column does not keep the key order: it sorts the keys by
     * length, then by name. SQLite keeps the order of the write.
     */
    protected function assertSameJsonData(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame(self::sortJsonKeys($expected), self::sortJsonKeys($actual), $message);
    }

    private static function sortJsonKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortJsonKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
