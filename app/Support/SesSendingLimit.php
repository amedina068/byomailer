<?php

namespace App\Support;

use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;

/**
 * The job middleware of the `ses-sending` limit. The newsletter chunks and
 * the sequence mails share it. `queue.ses_sending_limiter` selects where the
 * counters stay: in Redis, or in the cache store when the install has no
 * Redis (the Docker image with the database queue).
 */
final class SesSendingLimit
{
    public const NAME = 'ses-sending';

    public static function middleware(): RateLimited
    {
        $middleware = config('queue.ses_sending_limiter') === 'redis'
            ? new RateLimitedWithRedis(self::NAME)
            : new RateLimited(self::NAME);

        return $middleware->releaseAfter(1);
    }
}
