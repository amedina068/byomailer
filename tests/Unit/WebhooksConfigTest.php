<?php

namespace Tests\Unit;

use Tests\TestCase;

class WebhooksConfigTest extends TestCase
{
    public function test_webhooks_config_exposes_expected_defaults(): void
    {
        $this->assertSame(30, config('webhooks.delivery_retention_days'));
        $this->assertSame(25, config('webhooks.max_per_client'));
        $this->assertSame(15, config('webhooks.disable_after_failures'));
        $this->assertSame(10, config('webhooks.timeout_seconds'));
        $this->assertSame(2048, config('webhooks.response_body_cap_bytes'));
        $this->assertSame(60, config('webhooks.delivery_rate_per_minute'));
    }

    public function test_horizon_defines_webhooks_supervisor(): void
    {
        $this->assertArrayHasKey('webhooks', config('horizon.defaults'));
        $this->assertSame(['webhooks'], config('horizon.defaults.webhooks.queue'));
    }
}
