<?php

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebhookSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_endpoints_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('webhook_endpoints'));
        $this->assertTrue(Schema::hasColumns('webhook_endpoints', [
            'id', 'client_id', 'user_id', 'uuid', 'url', 'secret', 'description',
            'events', 'active', 'disabled_reason', 'consecutive_failures',
            'last_triggered_at', 'disabled_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_webhook_deliveries_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('webhook_deliveries'));
        $this->assertTrue(Schema::hasColumns('webhook_deliveries', [
            'id', 'client_id', 'webhook_endpoint_id', 'uuid', 'event_type',
            'payload', 'status', 'attempts', 'response_status', 'response_body',
            'error', 'dispatched_at', 'delivered_at', 'created_at', 'updated_at',
        ]));
    }
}
