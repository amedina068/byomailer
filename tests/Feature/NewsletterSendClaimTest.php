<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterScheduleService;
use App\Services\NewsletterTransport;
use App\Services\SendNewsletterService;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The web form, the REST API, the MCP server and the scheduler can change the
 * status of one newsletter at the same time. Each status change starts from
 * the status in the database, not from the status of a model that a request
 * read earlier. So a newsletter gets one send batch only.
 */
class NewsletterSendClaimTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function mutableStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'scheduled' => ['scheduled'],
        ];
    }

    #[DataProvider('mutableStatusProvider')]
    public function test_send_now_queues_the_newsletter_and_dispatches_one_batch_job(string $status): void
    {
        Bus::fake([DispatchNewsletterBatchJob::class]);
        $newsletter = $this->newsletter($status);

        $queued = app(NewsletterScheduleService::class)->sendNow($newsletter);

        $this->assertTrue($queued);
        $this->assertSame(NewsletterStatus::Queued, $this->statusOf($newsletter));
        Bus::assertDispatchedTimes(DispatchNewsletterBatchJob::class, 1);
    }

    public function test_send_now_does_nothing_when_the_scheduler_queued_the_newsletter_first(): void
    {
        Bus::fake([DispatchNewsletterBatchJob::class]);
        $staleCopy = $this->newsletter('scheduled');
        $this->claimAsTheScheduler($staleCopy);

        $queued = app(NewsletterScheduleService::class)->sendNow($staleCopy);

        $this->assertFalse($queued);
        $this->assertSame(NewsletterStatus::Queued, $this->statusOf($staleCopy));
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_unschedule_does_not_return_a_queued_newsletter_to_draft(): void
    {
        $staleCopy = $this->newsletter('scheduled');
        $this->claimAsTheScheduler($staleCopy);

        $unscheduled = app(NewsletterScheduleService::class)->unschedule($staleCopy);

        $this->assertFalse($unscheduled);
        $this->assertSame(NewsletterStatus::Queued, $this->statusOf($staleCopy));
        $this->assertNotNull(Newsletter::withoutGlobalScopes()->findOrFail($staleCopy->id)->scheduled_at);
    }

    public function test_schedule_does_not_reschedule_a_queued_newsletter(): void
    {
        $staleCopy = $this->newsletter('scheduled');
        $this->claimAsTheScheduler($staleCopy);

        $scheduled = app(NewsletterScheduleService::class)->schedule($staleCopy, now()->addWeek());

        $this->assertFalse($scheduled);
        $this->assertSame(NewsletterStatus::Queued, $this->statusOf($staleCopy));
    }

    /**
     * A send job can wait in the queue after a status change. It must not send
     * a newsletter that is no longer `queued`.
     *
     * @return array<string, array{string}>
     */
    public static function notQueuedStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'scheduled' => ['scheduled'],
            'failed' => ['failed'],
            'sent' => ['sent'],
            'ab_testing' => ['ab_testing'],
        ];
    }

    #[DataProvider('notQueuedStatusProvider')]
    public function test_the_send_job_sends_nothing_for_a_newsletter_that_is_not_queued(string $status): void
    {
        $newsletter = $this->newsletter($status);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldNotReceive('createTemplate');
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = Mockery::mock(Batch::class);
        $batch->shouldNotReceive('add');

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $this->assertSame($status, $this->statusOf($newsletter)->value);
    }

    private function newsletter(string $status): Newsletter
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        return Newsletter::factory()->for($user)->create([
            'status' => $status,
            'scheduled_at' => now()->subMinute(),
        ]);
    }

    /**
     * The same conditional update that `contact-mails:send-due-newsletters` runs.
     */
    private function claimAsTheScheduler(Newsletter $newsletter): void
    {
        Newsletter::withoutGlobalScopes()
            ->whereKey($newsletter->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'queued']);
    }

    private function statusOf(Newsletter $newsletter): NewsletterStatus
    {
        return Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->status;
    }
}
