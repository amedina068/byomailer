<?php

namespace App\Actions;

use App\Models\Newsletter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteNewsletter
{
    /**
     * Delete a draft or scheduled newsletter with its messages and its contact
     * mails, in one transaction.
     *
     * The delete runs only while the newsletter is still draft or scheduled in
     * the database. When the scheduler queued it first, nothing changes.
     *
     * @throws ValidationException when the newsletter is sending or sent
     */
    public function handle(Newsletter $newsletter): void
    {
        DB::transaction(function () use ($newsletter): void {
            $deleted = Newsletter::query()
                ->whereKey($newsletter->getKey())
                ->mutable()
                ->delete() === 1;

            if (! $deleted) {
                $status = Newsletter::query()->whereKey($newsletter->getKey())->value('status') ?? $newsletter->status;

                throw ValidationException::withMessages([
                    'status' => ["The newsletter is {$status->value}. Only a draft or scheduled newsletter can be deleted."],
                ]);
            }

            $newsletter->messages()->delete();
            $newsletter->contactMails()->delete();
        });
    }
}
