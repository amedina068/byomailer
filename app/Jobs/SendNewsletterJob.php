<?php

namespace App\Jobs;

use App\Enums\NewsletterStatus;
use App\Exceptions\MissingSesPermissionException;
use App\Mail\NewsletterBlockedMail;
use App\Models\Newsletter;
use App\Services\SendNewsletterService;
use App\Support\Tenant;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNewsletterJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A retry continues after the last contact that got a chunk job, so no
     * contact gets two chunk jobs. A retry also reuses the SES templates.
     */
    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(public Newsletter $newsletter)
    {
        $this->onQueue('newsletter');
    }

    public function handle(SendNewsletterService $sendNewsletterService): void
    {
        Tenant::run($this->newsletter->client_id, fn () => $this->send($sendNewsletterService));
    }

    private function send(SendNewsletterService $sendNewsletterService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('SendNewsletterJob started', [
            'newsletter_id' => $this->newsletter->id,
            'attempt' => $this->attempts(),
        ]);

        try {
            $sendNewsletterService->send($this->newsletter, $this->batch());

            Log::info('SendNewsletterJob completed', [
                'newsletter_id' => $this->newsletter->id,
            ]);
        } catch (Throwable $e) {
            Log::error('SendNewsletterJob exception during handle', [
                'newsletter_id' => $this->newsletter->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SendNewsletterJob failed', [
            'newsletter_id' => $this->newsletter->id,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        $this->newsletter->update(['status' => NewsletterStatus::Failed]);

        if ($exception instanceof MissingSesPermissionException) {
            $owner = $this->newsletter->user;

            if ($owner) {
                Mail::to($owner)->queue(new NewsletterBlockedMail(
                    $owner,
                    $this->newsletter,
                    $exception->missingPermissions(),
                ));
            }
        }
    }
}
