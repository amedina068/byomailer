<?php

namespace Tests\Feature;

use App\Jobs\SendNewsletterChunkJob;
use App\Jobs\SendSequenceContactMailJob;
use App\Support\SesSendingLimit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use ReflectionClass;
use Tests\TestCase;

/**
 * The send jobs share the `ses-sending` limit. An install with the redis
 * queue keeps the counters in Redis, as before. The Docker image has no
 * Redis, so its counters go to the cache store.
 */
class SesSendingLimiterTest extends TestCase
{
    public function test_the_redis_setting_gives_the_redis_limiter(): void
    {
        config(['queue.ses_sending_limiter' => 'redis']);

        $this->assertInstanceOf(RateLimitedWithRedis::class, SesSendingLimit::middleware());
    }

    public function test_the_cache_setting_gives_the_cache_limiter(): void
    {
        config(['queue.ses_sending_limiter' => 'cache']);

        $middleware = SesSendingLimit::middleware();

        $this->assertInstanceOf(RateLimited::class, $middleware);
        $this->assertNotInstanceOf(RateLimitedWithRedis::class, $middleware);
    }

    public function test_each_limiter_uses_the_ses_sending_limit_and_releases_after_one_second(): void
    {
        foreach (['redis', 'cache'] as $setting) {
            config(['queue.ses_sending_limiter' => $setting]);

            $middleware = SesSendingLimit::middleware();
            $reflection = new ReflectionClass(RateLimited::class);

            $this->assertSame('ses-sending', $reflection->getProperty('limiterName')->getValue($middleware));
            $this->assertSame(1, $reflection->getProperty('releaseAfter')->getValue($middleware));
        }
    }

    public function test_the_send_jobs_use_the_limiter_of_the_setting(): void
    {
        config(['queue.ses_sending_limiter' => 'cache']);

        foreach ([SendNewsletterChunkJob::class, SendSequenceContactMailJob::class] as $class) {
            $job = (new ReflectionClass($class))->newInstanceWithoutConstructor();

            $middleware = $job->middleware();

            $this->assertCount(1, $middleware);
            $this->assertNotInstanceOf(RateLimitedWithRedis::class, $middleware[0], $class);
            $this->assertInstanceOf(RateLimited::class, $middleware[0], $class);
        }
    }
}
