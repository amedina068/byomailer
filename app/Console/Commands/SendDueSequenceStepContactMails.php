<?php

namespace App\Console\Commands;

use App\Enums\SequenceStatus;
use App\Jobs\SendSequenceContactMailJob;
use App\Models\ContactMail;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Support\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Claims due sequence mails and dispatches them to SendSequenceContactMailJob.
 * The command does not send mail. The job sends through the `ses-sending`
 * limiter, so a large sequence stays under the SES max send rate.
 */
class SendDueSequenceStepContactMails extends Command
{
    public const TICK_LIMIT = 200;

    public const STALE_MINUTES = 15;

    protected $signature = 'contact-mails:send-due-sequence-steps';

    protected $description = 'Claim due sequence step contact mails and queue them for sending.';

    public function handle(Dispatcher $bus, NewsletterTransport $transport): int
    {
        $tickAt = now()->startOfSecond();
        $candidateIds = $this->candidateIds($tickAt);

        if ($candidateIds === []) {
            return self::SUCCESS;
        }

        $this->claimable(ContactMail::withoutGlobalScopes()->whereIn('id', $candidateIds), $tickAt)
            ->update(['reserved_at' => $tickAt]);

        $claimedUserIds = ContactMail::withoutGlobalScopes()
            ->join('contacts', 'contacts.id', '=', 'contact_mails.contact_id')
            ->whereIn('contact_mails.id', $candidateIds)
            ->where('contact_mails.reserved_at', $tickAt)
            ->pluck('contacts.user_id', 'contact_mails.id');

        $users = User::query()->whereIn('id', $claimedUserIds->unique())->get()->keyBy('id');

        $claimedUserIds->keys()
            ->groupBy(fn (int $contactMailId): int => $claimedUserIds[$contactMailId])
            ->each(function (Collection $contactMailIds, int $userId) use ($users, $bus, $transport, $tickAt): void {
                $user = $users->get($userId);

                if (! $user) {
                    return;
                }

                Tenant::run($user->client_id, function () use ($contactMailIds, $user, $bus, $transport, $tickAt): void {
                    foreach ($contactMailIds->chunk($transport->getChunkSize($user)) as $chunk) {
                        $this->dispatchChunk($bus, $user, $chunk->values()->all(), $tickAt);
                    }
                });
            });

        return self::SUCCESS;
    }

    /**
     * One seek over due sequence rows, oldest first, up to the tick limit.
     * The contact_mails_sequence_due_index (mailable_type, status,
     * scheduled_at) serves the filter and the order. A row of a paused
     * step or sequence stays pending and out of the tick, so it cannot
     * fill the tick limit, and it sends when the step goes live again.
     *
     * @return array<int, int>
     */
    private function candidateIds(CarbonInterface $tickAt): array
    {
        $query = ContactMail::withoutGlobalScopes()
            ->where('mailable_type', SequenceStep::class)
            ->where('scheduled_at', '<=', $tickAt)
            ->whereIn('mailable_id', SequenceStep::query()
                ->where('status', SequenceStatus::Live)
                ->whereIn('sequence_id', Sequence::withoutGlobalScopes()->where('status', SequenceStatus::Live)->select('id'))
                ->select('id'));

        return $this->claimable($query, $tickAt)
            ->whereHas('contact', fn (Builder $q) => $q->withoutGlobalScopes()->mailable())
            ->orderBy('scheduled_at')
            ->limit(self::TICK_LIMIT)
            ->pluck('id')
            ->all();
    }

    /**
     * Unsent rows that no live job holds: never reserved, or reserved longer
     * ago than STALE_MINUTES.
     */
    private function claimable(Builder $query, CarbonInterface $tickAt): Builder
    {
        return $query->where('status', 'pending')
            ->whereNull('sent_at')
            ->where(fn (Builder $q) => $q->whereNull('reserved_at')
                ->orWhere('reserved_at', '<', $tickAt->copy()->subMinutes(self::STALE_MINUTES)));
    }

    /**
     * @param  array<int, int>  $contactMailIds
     */
    private function dispatchChunk(Dispatcher $bus, User $user, array $contactMailIds, CarbonInterface $tickAt): void
    {
        try {
            $bus->dispatch(new SendSequenceContactMailJob($user, $contactMailIds, $tickAt));
        } catch (Throwable $e) {
            ContactMail::withoutGlobalScopes()
                ->whereIn('id', $contactMailIds)
                ->where('reserved_at', $tickAt)
                ->update(['reserved_at' => null]);

            report($e);
        }
    }
}
