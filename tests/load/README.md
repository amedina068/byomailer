# Load Tests

Smoke load tests for the BYOMailer API. Use [k6](https://k6.io) to run them.

## Setup

1. Install k6: `brew install k6` (macOS) or see [k6 install docs](https://k6.io/docs/get-started/installation/).
2. Generate an API token in the dashboard (Settings → API Tokens).
3. Export the token and base URL:

   ```bash
   export BASE_URL="https://app.example.test"
   export API_TOKEN="byo_..."
   ```

## Scenarios

### Baseline read mix

`baseline-read-mix.js` — simulates dashboard browsing (list + show requests across newsletters, contacts, lists, templates).

```bash
k6 run tests/load/baseline-read-mix.js
```

Default: 50 VUs, 30s ramp, 2m steady, 30s ramp-down. Override with `--vus 100 --duration 5m`.

### Newsletter create + preview

`newsletter-create-preview.js` — exercises the write path: preview recipient count, then create draft newsletters with filters.

```bash
k6 run tests/load/newsletter-create-preview.js
```

## Targets

Baseline targets at 50 VUs against a single web node:

| Endpoint | p95 latency | Error rate |
|----------|-------------|------------|
| `GET /api/v1/newsletters` | < 250ms | < 0.5% |
| `GET /api/v1/contacts` | < 350ms | < 0.5% |
| `POST /api/v1/newsletters/contacts/preview` | < 500ms | < 1% |
| `POST /api/v1/newsletters` | < 600ms | < 1% |

Adjust as you scale horizontally. Rate limits will start kicking in for `/test-mail` (5/min) and `/contacts/preview` (30/min) — load runners should use multiple tokens to spread the keying.
