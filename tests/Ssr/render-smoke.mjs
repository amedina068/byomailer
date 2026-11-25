// SSR render smoke (task T-A4-SSR / T-C4) — R4 tripwire.
//
// Boots the built Inertia v3 SSR bundle (bootstrap/ssr/ssr.js) and asks it to
// render a real page, asserting a non-empty HTML body. A `vite build --ssr` that
// compiles cleanly does NOT prove the SSR path works — if v3's createServer /
// createInertiaApp / Vue renderToString wiring regresses, the render returns an
// empty body and this smoke fails. That is exactly the silent CSR-degradation
// this guards against.
//
// Scope: renders the Welcome guest page, which exercises the full SSR pipeline
// without depending on authenticated shared-prop context. The authenticated path
// (Dashboard + AppLayout) needs the complete HandleInertiaRequests shared-prop
// set, which is brittle to hand-construct here and is covered by real-request
// production SSR instead.
//
// Run: `node tests/Ssr/render-smoke.mjs` (or `npm run test:ssr`). Requires a
// prior `npm run build` so bootstrap/ssr/ssr.js exists.

import { spawn } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';

const SSR_URL = 'http://127.0.0.1:13714';
const BUNDLE = 'bootstrap/ssr/ssr.js';

function fail(msg) {
    console.error(`SSR SMOKE FAILED: ${msg}`);
    process.exitCode = 1;
}

const server = spawn('node', [BUNDLE], { stdio: 'ignore' });
let done = false;

async function waitForListen() {
    for (let i = 0; i < 40; i += 1) {
        try {
            await fetch(SSR_URL);
            return true;
        } catch {
            await sleep(250);
        }
    }
    return false;
}

try {
    if (!(await waitForListen())) {
        fail('SSR server did not start listening on port 13714');
    } else {
        const page = {
            component: 'Welcome',
            props: { canLogin: true, canRegister: true, laravelVersion: '13', phpVersion: '8.4' },
            url: '/',
            version: 'smoke',
        };

        const res = await fetch(`${SSR_URL}/render`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(page),
        });

        const json = await res.json();
        const body = typeof json.body === 'string' ? json.body : '';

        if (body.length < 1000) {
            fail(`rendered body too short (${body.length} chars) — SSR likely degraded to empty/CSR`);
        } else if (!/<[a-z]/i.test(body)) {
            fail('rendered body contains no HTML tags');
        } else {
            console.log(`SSR SMOKE OK: Welcome rendered ${body.length} chars of HTML`);
            done = true;
        }
    }
} catch (e) {
    fail(String(e));
} finally {
    server.kill();
}

if (!done && process.exitCode !== 1) {
    process.exitCode = 1;
}
