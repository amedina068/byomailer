@use('Inertia\Ssr\SsrState')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <style>
            html {
                background-color: #F4F4F6;
            }

            body {
                visibility: hidden;
            }
        </style>

        {{-- With SSR, @inertiaHead renders the page title. A second <title> would make search engines show the app name only. --}}
        @unless (app(SsrState::class)->setPage($page)->dispatch())
            <title data-inertia>{{ config('app.name', 'Laravel') }}</title>
        @endunless

        <link rel="manifest" href="/manifest.json">
        <link rel="icon" href="/byomailer-favicon.png" type="image/png">
        <link rel="apple-touch-icon" href="/byomailer-favicon.png">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preconnect" href="https://api.fontshare.com" crossorigin>
        <link href="https://api.fontshare.com/v2/css?f[]=general-sans@400,500,600,700&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])

        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
