{{--
    The layout of the HTTP error pages. It loads no build assets and no
    outside fonts, so it renders also when the frontend build is missing.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('code') · @yield('title') · BYOMailer</title>
    <link rel="icon" href="/byomailer-favicon.png" type="image/png">
    <style>
        :root {
            --bm-base: #F4F4F6;
            --bm-surface: #FFFFFF;
            --bm-border: #E0E0E6;
            --bm-accent: #4A7A00;
            --bm-accent-text: #FFFFFF;
            --bm-text-1: #111118;
            --bm-text-2: #555566;
            --bm-text-3: #626273;
            --bm-radius: 4px;
            --bm-radius-md: 6px;
            --bm-font-sans: 'General Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --bm-font-mono: 'JetBrains Mono', 'SF Mono', 'Fira Code', monospace;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: var(--bm-base);
            color: var(--bm-text-1);
            font-family: var(--bm-font-sans);
            line-height: 1.5;
        }

        main {
            width: 100%;
            max-width: 440px;
            padding: 32px;
            background: var(--bm-surface);
            border: 1px solid var(--bm-border);
            border-radius: var(--bm-radius-md);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            color: var(--bm-text-2);
            font-size: 14px;
            font-weight: 600;
        }

        .brand img { width: 20px; height: 20px; }

        .code {
            margin: 0 0 4px;
            color: var(--bm-text-3);
            font-family: var(--bm-font-mono);
            font-size: 13px;
        }

        h1 { margin: 0 0 8px; font-size: 22px; font-weight: 600; }

        p { margin: 0 0 12px; color: var(--bm-text-2); font-size: 15px; }

        code {
            padding: 1px 4px;
            background: var(--bm-base);
            border-radius: var(--bm-radius);
            color: var(--bm-text-1);
            font-family: var(--bm-font-mono);
            font-size: 13px;
        }

        .action {
            display: inline-block;
            margin-top: 12px;
            padding: 8px 14px;
            background: var(--bm-accent);
            border-radius: var(--bm-radius);
            color: var(--bm-accent-text);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main>
        <div class="brand">
            <img src="/byomailer-favicon.png" alt="">
            <span>BYOMailer</span>
        </div>
        <p class="code">Error @yield('code')</p>
        <h1>@yield('title')</h1>
        @yield('message')
    </main>
</body>
</html>
