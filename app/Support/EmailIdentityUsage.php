<?php

namespace App\Support;

use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Support\Collection;

/**
 * Finds the live items that send from an email identity. A live item fails
 * to send when its email identity is removed. Draft and sent newsletters do
 * not count. A live step counts in a live and in a paused sequence. A paused
 * sequence has the status draft, and its live steps send again when it goes
 * live.
 */
class EmailIdentityUsage
{
    /**
     * @return array{newsletters: list<array{id: int, name: string}>, sequences: list<array{id: int, name: string}>, lists: list<array{id: int, name: string}>}
     */
    public static function of(EmailIdentity $identity): array
    {
        /** @var Collection<string, Collection<int, int>> $idsByType */
        $idsByType = Message::query()
            ->where('email_identity_id', $identity->id)
            ->get(['messageable_type', 'messageable_id'])
            ->groupBy('messageable_type')
            ->map(fn (Collection $messages): Collection => $messages->pluck('messageable_id')->unique()->values());

        $idsOf = fn (string $morphClass): Collection => $idsByType->get($morphClass, collect());

        $newsletters = Newsletter::query()
            ->whereIn('id', $idsOf((new Newsletter)->getMorphClass()))
            ->whereIn('status', NewsletterStatus::scheduledOrSending())
            ->orderBy('id')
            ->get(['id', 'title'])
            ->map(fn (Newsletter $newsletter): array => ['id' => $newsletter->id, 'name' => (string) $newsletter->title]);

        $liveStepSequenceIds = SequenceStep::query()
            ->whereIn('id', $idsOf((new SequenceStep)->getMorphClass()))
            ->where('status', SequenceStatus::Live)
            ->pluck('sequence_id');

        $sequences = Sequence::query()
            ->whereIn('id', $liveStepSequenceIds)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Sequence $sequence): array => ['id' => $sequence->id, 'name' => (string) $sequence->name]);

        $lists = MailList::query()
            ->whereIn('id', $idsOf((new MailList)->getMorphClass()))
            ->where('opt_in_enabled', true)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (MailList $list): array => ['id' => $list->id, 'name' => (string) $list->name]);

        return [
            'newsletters' => $newsletters->values()->all(),
            'sequences' => $sequences->values()->all(),
            'lists' => $lists->values()->all(),
        ];
    }

    /**
     * @param  array{newsletters: list<array{id: int, name: string}>, sequences: list<array{id: int, name: string}>, lists: list<array{id: int, name: string}>}  $usage
     */
    public static function isInUse(array $usage): bool
    {
        return $usage['newsletters'] !== [] || $usage['sequences'] !== [] || $usage['lists'] !== [];
    }
}
