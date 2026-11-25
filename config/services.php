<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sqs_ses_events' => [
        'key' => env('PLATFORM_AWS_ACCESS_KEY_ID'),
        'secret' => env('PLATFORM_AWS_SECRET_ACCESS_KEY'),
        'region' => env('PLATFORM_AWS_REGION', 'us-east-1'),
        'queue_url' => env('SES_EVENTS_SQS_QUEUE_URL'),
        'queue_arn' => env('SES_EVENTS_SQS_QUEUE_ARN'),
    ],

    'maxmind' => [
        'database_path' => env('MAXMIND_DATABASE_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'byomailer' => [
        'api_url' => env('BYOMAILER_API_URL'),
        'api_token' => env('BYOMAILER_API_TOKEN'),
        'list_id' => env('BYOMAILER_LIST_ID'),
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'cname_target' => env('CLOUDFLARE_CNAME_TARGET'),
        'origin_server' => env('CLOUDFLARE_ORIGIN_SERVER', env('CLOUDFLARE_CNAME_TARGET')),
        'origin_sni' => env('CLOUDFLARE_ORIGIN_SNI', env('CLOUDFLARE_CNAME_TARGET')),
    ],

    'tracking_proxy' => [
        'ip' => env('TRACKING_PROXY_IP'),
        'ask_token' => env('TRACKING_PROXY_ASK_TOKEN'),
    ],

    'snapsbrew' => [
        'base_url' => env('SNAPSBREW_BASE_URL', 'https://app.snapsbrew.com'),
        'signed_url_key' => env('SNAPSBREW_SIGNED_URL_KEY'),
        'og_template_id' => env('SNAPSBREW_OG_TEMPLATE_ID'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
    ],

];
