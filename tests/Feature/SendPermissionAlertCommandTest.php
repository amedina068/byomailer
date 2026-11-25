<?php

namespace Tests\Feature;

use App\Mail\SesPermissionAlertMail;
use App\Models\EmailCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendPermissionAlertCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_alert_on_first_detection(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
            'permissions_alert_count' => 0,
            'permissions_alerted_at' => null,
        ]);

        $this->artisan('ses:send-permission-alerts')->assertSuccessful();

        Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) {
            return $job->mailable instanceof SesPermissionAlertMail;
        });

        $credential = $user->sesCredential()->withoutGlobalScopes()->first();
        $this->assertSame(1, $credential->permissions_alert_count);
        $this->assertNotNull($credential->permissions_alerted_at);
    }

    public function test_it_skips_alerts_between_first_and_sixth(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
            'permissions_alert_count' => 2,
            'permissions_alerted_at' => now()->subHour(),
        ]);

        $this->artisan('ses:send-permission-alerts')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);

        $this->assertSame(3, $user->sesCredential()->withoutGlobalScopes()->first()->permissions_alert_count);
    }

    public function test_it_sends_alert_on_sixth_detection(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
            'permissions_alert_count' => 5,
            'permissions_alerted_at' => now()->subHours(4),
        ]);

        $this->artisan('ses:send-permission-alerts')->assertSuccessful();

        Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) {
            return $job->mailable instanceof SesPermissionAlertMail;
        });
    }

    public function test_it_sends_alert_every_twelve_hours_after_sixth(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
            'permissions_alert_count' => 10,
            'permissions_alerted_at' => now()->subHours(13),
        ]);

        $this->artisan('ses:send-permission-alerts')->assertSuccessful();

        Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) {
            return $job->mailable instanceof SesPermissionAlertMail;
        });
    }

    public function test_it_skips_alert_when_under_twelve_hours_after_sixth(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
            'permissions_alert_count' => 10,
            'permissions_alerted_at' => now()->subHours(6),
        ]);

        $this->artisan('ses:send-permission-alerts')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);
    }

    public function test_it_resets_alert_state_when_permissions_restored(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
            'permissions_checked_at' => now(),
            'permissions_alert_count' => 5,
            'permissions_alerted_at' => now()->subHour(),
        ]);

        $this->artisan('ses:send-permission-alerts')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);

        $credential = $user->sesCredential()->withoutGlobalScopes()->first();
        $this->assertSame(0, $credential->permissions_alert_count);
        $this->assertNull($credential->permissions_alerted_at);
    }

    public function test_it_skips_credentials_without_permissions_checked(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => null,
        ]);

        $this->artisan('ses:send-permission-alerts')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);
    }

    public function test_it_does_not_alert_when_all_permissions_granted(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
            'permissions_checked_at' => now(),
        ]);

        $this->artisan('ses:send-permission-alerts')->assertSuccessful();

        Queue::assertNotPushed(SendQueuedMailable::class);
    }
}
