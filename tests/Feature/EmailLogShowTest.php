<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailLogShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_email_log(): void
    {
        $user = User::factory()->create();

        $log = EmailLog::factory()->create([
            'client_id' => $user->client_id,
            'operation' => 'SendEmail',
            'recipient' => 'test@example.com',
            'message_id' => 'ses-msg-456',
            'status' => 'delivered',
            'request_payload' => ['from' => 'noreply@example.com', 'to' => 'test@example.com'],
            'response_payload' => ['MessageId' => 'ses-msg-456'],
            'sns_payload' => ['notificationType' => 'Delivery'],
        ]);

        $response = $this->actingAs($user)->get(route('email-logs.show', $log));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('email-logs/Show')
            ->has('emailLog', fn (Assert $prop) => $prop
                ->where('id', $log->id)
                ->where('operation', 'SendEmail')
                ->where('recipient', 'test@example.com')
                ->where('message_id', 'ses-msg-456')
                ->where('status', 'delivered')
                ->where('request_payload.from', 'noreply@example.com')
                ->where('response_payload.MessageId', 'ses-msg-456')
                ->where('sns_payload.notificationType', 'Delivery')
                ->etc()
            )
        );
    }

    public function test_user_cannot_view_other_clients_email_log(): void
    {
        $user = User::factory()->create();
        $otherLog = EmailLog::factory()->create();

        $response = $this->actingAs($user)->get(route('email-logs.show', $otherLog));

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $log = EmailLog::factory()->create();

        $response = $this->get(route('email-logs.show', $log));

        $response->assertRedirect(route('login'));
    }

    public function test_show_page_handles_null_payloads(): void
    {
        $user = User::factory()->create();

        $log = EmailLog::factory()->create([
            'client_id' => $user->client_id,
            'status' => 'failed',
            'error' => 'Throttling: Maximum sending rate exceeded.',
            'request_payload' => null,
            'response_payload' => null,
            'sns_payload' => null,
        ]);

        $response = $this->actingAs($user)->get(route('email-logs.show', $log));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('email-logs/Show')
            ->has('emailLog', fn (Assert $prop) => $prop
                ->where('id', $log->id)
                ->where('status', 'failed')
                ->where('error', 'Throttling: Maximum sending rate exceeded.')
                ->where('request_payload', null)
                ->where('response_payload', null)
                ->where('sns_payload', null)
                ->etc()
            )
        );
    }
}
