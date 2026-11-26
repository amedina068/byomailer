<?php

use App\Support\RuntimeDefaults;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    // APP_URL, then https://APP_DOMAIN, then http://localhost:8080 (the port
    // of the Docker image).
    'url' => RuntimeDefaults::appUrl(env('APP_URL'), env('APP_DOMAIN')),

    /*
    |--------------------------------------------------------------------------
    | Previous Application URLs
    |--------------------------------------------------------------------------
    |
    | A comma-separated list of the old public URLs of this application, for
    | example https://byomailer.com. Signed links in emails that were sent
    | before a move keep the old host. When a redirect brings such a link to
    | the current URL, the signature check also accepts these old URLs.
    |
    */

    'previous_urls' => array_values(array_filter(array_map(
        fn (string $url): string => rtrim(trim($url), '/'),
        explode(',', (string) env('APP_PREVIOUS_URLS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    // APP_KEY, then the file in APP_KEY_FILE. The Docker image writes that
    // file (/data/.secrets/app_key) on the first start.
    'key' => RuntimeDefaults::appKey(env('APP_KEY'), env('APP_KEY_FILE')),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | When false, the /register page and the register endpoint answer 404.
    | Create the first user with `php artisan byomailer:install`.
    |
    */

    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | API Rate Limit
    |--------------------------------------------------------------------------
    |
    | The number of REST API requests that one user can send in one minute.
    | A request over the limit gets a 429 response with a Retry-After header.
    |
    */

    'api_rate_limit' => (int) env('API_RATE_LIMIT', 300),

    /*
    |--------------------------------------------------------------------------
    | Admin Emails
    |--------------------------------------------------------------------------
    |
    | A comma-separated list of the users that can open the Horizon dashboard
    | in a non-local environment. When the list is empty, nobody can open it.
    |
    */

    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

];
