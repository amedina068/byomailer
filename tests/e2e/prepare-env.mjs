// Writes .env.e2e for the Playwright end-to-end tests.
//
// The file starts from .env.example. The overrides below make the app run on
// its own: a separate SQLite file, no real mail, no queue worker, no SSR, and
// no call to AWS. The script keeps the APP_KEY of an earlier .env.e2e. If no
// earlier key exists, it generates a new key. .gitignore excludes .env.e2e.
//
// The SQLite file is in the temporary folder of the system, because the
// migrations run much faster there than on some disks. The name includes a
// hash of the checkout path, so two checkouts do not share one file. Set
// E2E_DATABASE to use a different file.
import { createHash, randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const envFile = resolve(root, '.env.e2e');
const checkoutHash = createHash('sha1').update(root).digest('hex').slice(0, 8);
const databaseFile =
    process.env.E2E_DATABASE ??
    join(tmpdir(), `byomailer-e2e-${checkoutHash}.sqlite`);
const port = process.env.E2E_PORT ?? '8123';

function readKey(path) {
    if (!existsSync(path)) {
        return null;
    }

    const match = readFileSync(path, 'utf8').match(/^APP_KEY=(base64:\S+)$/m);

    return match ? match[1] : null;
}

const appKey =
    readKey(envFile) ?? `base64:${randomBytes(32).toString('base64')}`;

const overrides = {
    APP_ENV: 'e2e',
    APP_KEY: appKey,
    APP_DEBUG: 'true',
    // The debug bar covers the bottom of the page and blocks clicks.
    DEBUGBAR_ENABLED: 'false',
    APP_URL: `http://127.0.0.1:${port}`,
    BCRYPT_ROUNDS: '4',
    PHP_CLI_SERVER_WORKERS: '4',
    REGISTRATION_ENABLED: 'false',
    LOG_STACK: 'single',
    LOG_LEVEL: 'warning',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: `"${databaseFile}"`,
    SESSION_DRIVER: 'file',
    SESSION_SECURE_COOKIE: 'false',
    BROADCAST_CONNECTION: 'null',
    QUEUE_CONNECTION: 'sync',
    // An array cache resets on each request, so the login rate limit does
    // not block a second test run in the same minute.
    CACHE_STORE: 'array',
    MAIL_MAILER: 'array',
    INERTIA_SSR_ENABLED: 'false',
    // Each AWS SDK call goes to a closed local port and fails at once.
    AWS_ENDPOINT_URL: 'http://aws-calls-are-blocked-in-e2e.localhost:1',
};

const lines = readFileSync(resolve(root, '.env.example'), 'utf8').split('\n');
const written = new Set();

const output = lines.map((line) => {
    const key = line.match(/^([A-Z0-9_]+)=/)?.[1];

    if (key === undefined || !(key in overrides)) {
        return line;
    }

    written.add(key);

    return `${key}=${overrides[key]}`;
});

for (const [key, value] of Object.entries(overrides)) {
    if (!written.has(key)) {
        output.push(`${key}=${value}`);
    }
}

writeFileSync(envFile, output.join('\n'));
writeFileSync(databaseFile, '');

console.log(`Wrote ${envFile} and an empty ${databaseFile}.`);
