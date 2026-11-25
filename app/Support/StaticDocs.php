<?php

namespace App\Support;

class StaticDocs
{
    /**
     * The host that the files in public/docs use for example URLs.
     */
    public const PLACEHOLDER_URL = 'https://byomailer.example';

    /**
     * Replace the placeholder host with APP_URL, so the examples point at this install.
     */
    public static function withAppUrl(string $content): string
    {
        return str_replace(self::PLACEHOLDER_URL, rtrim((string) config('app.url'), '/'), $content);
    }
}
