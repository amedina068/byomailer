<?php

namespace App\Console\Commands;

use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\Newsletter;
use Illuminate\Console\Command;
use Throwable;

class SendDueNewsletterContactMails extends Command
{
    protected $signature = 'contact-mails:send-due-newsletters';

    protected $description = 'Dispatch newsletter sending jobs for newsletters whose schedule is due.';

    /**
     * The scheduler has no authenticated user, so the query reads all clients.
     * A conditional update claims each newsletter before the dispatch. If a
     * concurrent run claimed it first, this run skips it. If the dispatch
     * fails, the newsletter goes back to "scheduled" for the next run.
     */
    public function handle(): int
    {
        Newsletter::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->whereNull('sent_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->lazyById()
            ->each(function (Newsletter $newsletter): void {
                $claimed = Newsletter::withoutGlobalScopes()
                    ->whereKey($newsletter->id)
                    ->where('status', 'scheduled')
                    ->update(['status' => 'queued']);

                if ($claimed === 0) {
                    return;
                }

                try {
                    dispatch(new DispatchNewsletterBatchJob($newsletter->refresh()));
                } catch (Throwable $e) {
                    Newsletter::withoutGlobalScopes()
                        ->whereKey($newsletter->id)
                        ->where('status', 'queued')
                        ->update(['status' => 'scheduled']);

                    report($e);
                }
            });

        return self::SUCCESS;
    }
}
