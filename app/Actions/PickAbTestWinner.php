<?php

namespace App\Actions;

use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Events\NewsletterSent;
use App\Models\Newsletter;
use Illuminate\Validation\ValidationException;

class PickAbTestWinner
{
    /**
     * Record the winner of an A/B test and mark the newsletter as sent.
     *
     * Each half of the audience already got one variant, so this sends no
     * email. The update runs only while the status is `ab_testing`. When two
     * requests pick a winner at the same time, one wins and `newsletter.sent`
     * fires one time.
     *
     * @throws ValidationException when the newsletter is not in `ab_testing`,
     *                             it has no B variant, or another request
     *                             picked first
     */
    public function handle(Newsletter $newsletter, AbVariant $variant): Newsletter
    {
        if ($newsletter->status !== NewsletterStatus::AbTesting) {
            throw ValidationException::withMessages([
                'status' => ["You can pick a winner only while the A/B test runs. This newsletter is {$newsletter->status->value}."],
            ]);
        }

        if (! $newsletter->ab_test_enabled || ! $newsletter->messageB()->exists()) {
            throw ValidationException::withMessages([
                'ab_test_enabled' => ['Newsletter has no A/B variant configured.'],
            ]);
        }

        $picked = Newsletter::query()
            ->whereKey($newsletter->getKey())
            ->where('status', NewsletterStatus::AbTesting)
            ->update([
                'ab_test_winner_variant' => $variant,
                'status' => NewsletterStatus::Sent,
                'sent_at' => now(),
            ]) === 1;

        if (! $picked) {
            throw ValidationException::withMessages([
                'status' => ['Another request picked the A/B winner first.'],
            ]);
        }

        NewsletterSent::dispatch($newsletter->refresh());

        return $newsletter;
    }
}
