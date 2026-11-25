<?php

namespace Tests\Feature\Api;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_logs_can_be_listed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailLog::factory()->count(3)->create(['client_id' => $user->client_id]);
        EmailLog::factory()->create(); // different client

        $response = $this->getJson('/api/v1/email-logs');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_email_logs_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailLog::factory()->create(['client_id' => $user->client_id, 'status' => 'sent']);
        EmailLog::factory()->create(['client_id' => $user->client_id, 'status' => 'failed']);
        EmailLog::factory()->create(['client_id' => $user->client_id, 'status' => 'delivered']);

        $response = $this->getJson('/api/v1/email-logs?status=failed');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_email_logs_can_be_filtered_by_operation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailLog::factory()->create(['client_id' => $user->client_id, 'operation' => 'SendBulkEmail']);
        EmailLog::factory()->create(['client_id' => $user->client_id, 'operation' => 'CreateEmailTemplate']);

        $response = $this->getJson('/api/v1/email-logs?operation=SendBulkEmail');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.operation', 'SendBulkEmail');
    }

    public function test_email_logs_can_be_filtered_by_recipient(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailLog::factory()->create(['client_id' => $user->client_id, 'recipient' => 'alice@example.com']);
        EmailLog::factory()->create(['client_id' => $user->client_id, 'recipient' => 'bob@example.com']);

        $response = $this->getJson('/api/v1/email-logs?recipient=alice');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recipient', 'alice@example.com');
    }

    public function test_email_log_can_be_shown(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $log = EmailLog::factory()->create([
            'client_id' => $user->client_id,
            'operation' => 'SendBulkEmail',
            'recipient' => 'test@example.com',
            'message_id' => 'ses-msg-123',
            'status' => 'delivered',
        ]);

        $response = $this->getJson("/api/v1/email-logs/{$log->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.operation', 'SendBulkEmail')
            ->assertJsonPath('data.recipient', 'test@example.com')
            ->assertJsonPath('data.message_id', 'ses-msg-123')
            ->assertJsonPath('data.status', 'delivered');
    }

    public function test_user_cannot_access_other_clients_logs(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $otherLog = EmailLog::factory()->create();

        $response = $this->getJson("/api/v1/email-logs/{$otherLog->id}");

        $response->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/email-logs');

        $response->assertUnauthorized();
    }

    public function test_email_logs_are_paginated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailLog::factory()->count(30)->create(['client_id' => $user->client_id]);

        $response = $this->getJson('/api/v1/email-logs?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonStructure(['meta' => ['next_cursor', 'prev_cursor']]);
    }
}
