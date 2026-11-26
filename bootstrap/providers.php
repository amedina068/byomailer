<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,

    /*
     * Horizon runs only the redis queue. composer.json lists it in
     * "dont-discover", so an install with the database queue (the Docker
     * image) has no /horizon route and no horizon commands. Laravel reads
     * this file after the config, and `config:cache` stores the result.
     */
    ...(config('queue.default') === 'redis' ? [
        Laravel\Horizon\HorizonServiceProvider::class,
        HorizonServiceProvider::class,
    ] : []),
];
