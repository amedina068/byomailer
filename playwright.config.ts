import { defineConfig, devices } from '@playwright/test';

// The end-to-end tests run against `php artisan serve` with .env.e2e.
// tests/e2e/prepare-env.mjs writes that file. The tests use the built Vite
// assets, so run `npm run build` first. CONTRIBUTING.md has the steps.
const port = process.env.E2E_PORT ?? '8123';
const baseURL = `http://127.0.0.1:${port}`;
const php = process.env.E2E_PHP ?? 'php';

// Set E2E_BROWSER_CHANNEL=chrome to use the installed Google Chrome instead
// of the Chromium that `npx playwright install chromium` downloads.
const channel = process.env.E2E_BROWSER_CHANNEL || undefined;

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
            use: { ...devices['Desktop Chrome'], channel },
        },
        {
            name: 'chromium',
            testIgnore: /auth\.setup\.ts/,
            dependencies: ['setup'],
            use: { ...devices['Desktop Chrome'], channel },
        },
    ],
    webServer: {
        command: [
            'node tests/e2e/prepare-env.mjs',
            `${php} artisan migrate:fresh --env=e2e --force --no-interaction --seed --seeder="Database\\Seeders\\E2eSeeder"`,
            `${php} artisan serve --env=e2e --host=127.0.0.1 --port=${port} --no-reload`,
        ].join(' && '),
        url: `${baseURL}/up`,
        env: { APP_ENV: 'e2e', E2E_PORT: port },
        reuseExistingServer: false,
        timeout: 180_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
