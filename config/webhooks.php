<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Delivery Retention
    |--------------------------------------------------------------------------
    |
    | How many days to keep webhook_deliveries rows before they are pruned by
    | the scheduled model:prune command.
    |
    */

    'delivery_retention_days' => (int) env('WEBHOOKS_DELIVERY_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Endpoint Limits
    |--------------------------------------------------------------------------
    |
    | The maximum number of webhook endpoints a single client may register.
    |
    */

    'max_per_client' => (int) env('WEBHOOKS_MAX_PER_CLIENT', 25),

    /*
    |--------------------------------------------------------------------------
    | Auto-disable Threshold
    |--------------------------------------------------------------------------
    |
    | After this many consecutive failed deliveries an endpoint is automatically
    | disabled with disabled_reason = "auto_failures".
    |
    */

    'disable_after_failures' => (int) env('WEBHOOKS_DISABLE_AFTER_FAILURES', 15),

    /*
    |--------------------------------------------------------------------------
    | Delivery HTTP Settings
    |--------------------------------------------------------------------------
    |
    | timeout_seconds is the per-request HTTP timeout. It MUST stay below the
    | DeliverWebhookJob process timeout ($timeout = 30) — otherwise the worker
    | can be killed before the HTTP timeout exception propagates, leaving the
    | delivery stuck in "pending" with no failed() hook and no retry.
    |
    */

    'timeout_seconds' => (int) env('WEBHOOKS_TIMEOUT_SECONDS', 10),

    'response_body_cap_bytes' => (int) env('WEBHOOKS_RESPONSE_BODY_CAP_BYTES', 2048),

    /*
    |--------------------------------------------------------------------------
    | Per-client Delivery Rate Limit
    |--------------------------------------------------------------------------
    |
    | Maximum webhook deliveries dispatched per minute for a single client.
    |
    */

    'delivery_rate_per_minute' => (int) env('WEBHOOKS_DELIVERY_RATE_PER_MINUTE', 60),

];
