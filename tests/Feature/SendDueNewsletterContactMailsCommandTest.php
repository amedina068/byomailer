<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendDueNewsletterContactMailsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_jobs_for_due_newsletters(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $dueNewsletter = Newsletter::factory()
            ->for($user)
            ->create([
                'status' => 'scheduled',
                'scheduled_at' => now()->subMinute(),
            ]);

        $futureNewsletter = Newsletter::factory()
            ->for($user)
            ->create([
                'status' => 'scheduled',
                'scheduled_at' => now()->addHour(),
            ]);

        $pureDraftNewsletter = Newsletter::factory()
            ->for($user)
            ->create([
                'status' => 'draft',
                'scheduled_at' => null,
            ]);

        $sentNewsletter = Newsletter::factory()
            ->for($user)
            ->create([
                'status' => 'sent',
                'scheduled_at' => now()->subHour(),
                'sent_at' => now()->subMinutes(10),
            ]);

        $dueCount = Newsletter::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->whereNull('sent_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->count();

        $this->assertSame(1, $dueCount);

        $this->artisan('contact-mails:send-due-newsletters')->assertExitCode(0);

        $dueNewsletter->refresh();
        $this->assertSame(NewsletterStatus::Queued, $dueNewsletter->status);

        Bus::assertDispatched(DispatchNewsletterBatchJob::class, function (DispatchNewsletterBatchJob $job) use ($dueNewsletter): bool {
            return $job->newsletter->is($dueNewsletter);
        });

        Bus::assertDispatchedTimes(DispatchNewsletterBatchJob::class, 1);

        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class, function (DispatchNewsletterBatchJob $job) use ($futureNewsletter, $sentNewsletter, $pureDraftNewsletter): bool {
            return $job->newsletter->is($futureNewsletter)
                || $job->newsletter->is($sentNewsletter)
                || $job->newsletter->is($pureDraftNewsletter);
        });

        Bus::fake();

        $this->artisan('contact-mails:send-due-newsletters')->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'contact-mails:send-due-newsletters'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_it_dispatches_due_newsletters_of_every_client(): void
    {
        Bus::fake();

        $first = Newsletter::factory()->for(User::factory())->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);
        $second = Newsletter::factory()->for(User::factory())->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->assertNotSame($first->client_id, $second->client_id);

        $this->artisan('contact-mails:send-due-newsletters')->assertExitCode(0);

        $this->assertSame(NewsletterStatus::Queued, $first->fresh()->status);
        $this->assertSame(NewsletterStatus::Queued, $second->fresh()->status);
        Bus::assertDispatchedTimes(DispatchNewsletterBatchJob::class, 2);
    }

    public function test_it_does_not_dispatch_a_newsletter_claimed_by_a_concurrent_run(): void
    {
        Bus::fake();

        $newsletter = Newsletter::factory()->for(User::factory())->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        Newsletter::retrieved(function (Newsletter $model): void {
            Newsletter::withoutGlobalScopes()->whereKey($model->id)->update(['status' => 'queued']);
        });

        $this->artisan('contact-mails:send-due-newsletters')->assertExitCode(0);

        $this->assertSame(NewsletterStatus::Queued, $newsletter->fresh()->status);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_a_failed_dispatch_returns_the_newsletter_to_scheduled_and_the_run_continues(): void
    {
        Exceptions::fake();

        $user = User::factory()->create();
        $broken = Newsletter::factory()->for($user)->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(2),
        ]);
        $healthy = Newsletter::factory()->for($user)->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $dispatched = [];
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (DispatchNewsletterBatchJob $job) use ($broken, &$dispatched): void {
            if ($job->newsletter->is($broken)) {
                throw new RuntimeException('Queue connection refused');
            }

            $dispatched[] = $job->newsletter->id;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->artisan('contact-mails:send-due-newsletters')->assertExitCode(0);

        $this->assertSame(NewsletterStatus::Scheduled, $broken->fresh()->status);
        $this->assertSame(NewsletterStatus::Queued, $healthy->fresh()->status);
        $this->assertSame([$healthy->id], $dispatched);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Queue connection refused');
    }
}
