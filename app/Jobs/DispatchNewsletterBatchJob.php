<?php

namespace App\Jobs;

use App\Enums\NewsletterStatus;
use App\Events\NewsletterSent;
use App\Models\Newsletter;
use App\Services\SesBulkEmailService;
use App\Support\Tenant;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchNewsletterBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(public Newsletter $newsletter)
    {
        $this->onQueue('newsletter');
    }

    public function handle(): void
    {
        Tenant::run($this->newsletter->client_id, fn () => $this->dispatchBatch());
    }

    private function dispatchBatch(): void
    {
        Log::info('DispatchNewsletterBatchJob started', [
            'newsletter_id' => $this->newsletter->id,
        ]);

        $newsletter = $this->newsletter;
        $newsletterId = $newsletter->id;

        // A batch callback runs in the worker of the last job, so the callback sets its own tenant.
        // Laravel runs "finally" when every job ran one time, also when a chunk failed.
        // "then" runs only when no job failed, so the batch does not use it.
        Bus::batch([new SendNewsletterJob($newsletter)])
            ->name("newsletter-{$newsletterId}")
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($newsletterId): void {
                $newsletter = Newsletter::withoutGlobalScopes()->find($newsletterId);

                if (! $newsletter) {
                    return;
                }

                Tenant::run($newsletter->client_id, function () use ($newsletter, $batch): void {
                    if (self::noChunkSucceeded($batch)) {
                        $newsletter->update(['status' => NewsletterStatus::Failed]);
                    } else {
                        self::finalizeNewsletter($newsletter);
                    }

                    self::deleteTemplates($newsletter);
                });
            })
            ->onQueue('newsletter')
            ->dispatch();

        Log::info('DispatchNewsletterBatchJob completed', [
            'newsletter_id' => $this->newsletter->id,
        ]);
    }

    /**
     * The batch starts with one SendNewsletterJob. That job adds one chunk job
     * for each group of contacts. If the batch has failures and one job or
     * no job succeeded, no chunk sent mail. A failed SendNewsletterJob also
     * sets the status `failed` in its own failed() method.
     */
    private static function noChunkSucceeded(Batch $batch): bool
    {
        return $batch->hasFailures() && $batch->totalJobs - $batch->failedJobs <= 1;
    }

    private static function deleteTemplates(Newsletter $newsletter): void
    {
        $sesService = app(SesBulkEmailService::class);
        $owner = $newsletter->user;

        if ($newsletter->template_name) {
            $sesService->deleteTemplate($newsletter->template_name, $owner);
        }

        if ($newsletter->ab_template_name) {
            $sesService->deleteTemplate($newsletter->ab_template_name, $owner);
        }
    }

    /**
     * Finalize a newsletter after every job of its send batch ran one time.
     * A failed chunk does not stop this: its mails keep the status `failed`.
     * Fires the newsletter.sent webhook event once for a newsletter without an
     * A/B test. For an A/B test, the PickAbTestWinner Action fires it.
     */
    public static function finalizeNewsletter(Newsletter $newsletter): void
    {
        $actualCount = $newsletter->contactMails()->count();

        if ($newsletter->ab_test_enabled) {
            $newsletter->forceFill([
                'status' => NewsletterStatus::AbTesting,
                'total_contacts_reached' => $actualCount,
            ])->save();

            return;
        }

        $alreadySent = $newsletter->status === NewsletterStatus::Sent;

        $newsletter->forceFill([
            'status' => NewsletterStatus::Sent,
            'sent_at' => now(),
            'total_contacts_reached' => $actualCount,
        ])->save();

        if (! $alreadySent) {
            NewsletterSent::dispatch($newsletter);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('DispatchNewsletterBatchJob failed', [
            'newsletter_id' => $this->newsletter->id,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        $this->newsletter->update(['status' => NewsletterStatus::Failed]);
    }
}
