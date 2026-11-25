<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\WeeklySummaryMail;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendWeeklySummaryMailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_summary_to_active_users_with_expected_metrics(): void
    {
        Queue::fake();

        $now = CarbonImmutable::parse('2025-02-07 22:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $user = User::factory()->for(Client::factory()->state(['timezone' => 'America/New_York']))->create();
        $client = $user->client;

        $inRangeContact = Contact::factory()
            ->for($client)
            ->for($user)
            ->create([
                'created_at' => $now->copy()->subDays(2),
            ]);

        Contact::factory()
            ->for($client)
            ->for($user)
            ->create([
                'created_at' => $now->copy()->subDays(10),
            ]);

        Contact::factory()
            ->for($client)
            ->for($user)
            ->create([
                'created_at' => $now->copy()->subDays(30),
                'unsubscribed_at' => $now->copy()->subDay(),
            ]);

        Contact::factory()
            ->for($client)
            ->for($user)
            ->create([
                'created_at' => $now->copy()->subDays(45),
                'unsubscribed_at' => $now->copy()->subDays(8),
            ]);

        $recentNewsletter = Newsletter::factory()->for($user)->create();
        $oldNewsletter = Newsletter::factory()->for($user)->create();

        ContactMail::factory()
            ->for($inRangeContact, 'contact')
            ->forNewsletter($recentNewsletter)
            ->create([
                'sent_at' => $now->copy()->subHours(3),
            ]);

        ContactMail::factory()
            ->for($inRangeContact, 'contact')
            ->forNewsletter($oldNewsletter)
            ->create([
                'sent_at' => $now->copy()->subDays(8),
            ]);

        User::factory()->for($client)->create([
            'role' => UserRole::Invited,
            'email' => 'invited@example.com',
        ]);

        User::factory()->for($client)->create([
            'archived_at' => now(),
            'email' => 'archived@example.com',
        ]);

        $this->artisan('summary:send-weekly')->assertExitCode(0);

        Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) use ($user): bool {
            $mailable = $job->mailable;

            if (! $mailable instanceof WeeklySummaryMail || ! $mailable->hasTo($user->email)) {
                return false;
            }

            // The command queues the mail inside Tenant::run(), so the worker builds it as this client.
            $metrics = Tenant::run($user->client_id, fn (): array => $mailable->metrics());

            return $metrics['new_subscribers'] === 1
                && $metrics['new_unsubscribes'] === 1
                && $metrics['active_subscribers'] === 2
                && $metrics['mails_sent'] === 1;
        });

        Queue::assertPushed(SendQueuedMailable::class, 1);

        CarbonImmutable::setTestNow();
    }

    public function test_it_skips_clients_outside_target_hour(): void
    {
        Queue::fake();

        $user = User::factory()->for(Client::factory()->state(['timezone' => 'America/New_York']))->create();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-07 21:00:00', 'UTC'));

        $this->artisan('summary:send-weekly')->assertExitCode(0);

        Queue::assertNothingPushed();

        CarbonImmutable::setTestNow();
    }

    public function test_the_scheduler_runs_the_summary_every_hour_from_thursday_to_saturday(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'summary:send-weekly'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * 4,5,6', $event->expression);
    }

    public function test_the_summary_mail_renders_the_numbers_of_the_week(): void
    {
        $user = User::factory()->for(Client::factory()->state(['name' => 'Acme', 'timezone' => 'UTC']))->create();
        Contact::factory()->for($user->client)->for($user)->create(['created_at' => now()->subDay()]);

        $this->actingAsTenant($user->client_id);

        $html = (new WeeklySummaryMail($user->client, $user, now()->subWeek(), now()))->render();

        $this->assertStringContainsString('Weekly summary for Acme', $html);
        $this->assertStringContainsString('New subscribers', $html);
    }
}
