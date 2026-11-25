<?php

namespace App\Support;

use App\Enums\FilterCategory;
use App\Enums\NewsletterStatus;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use Illuminate\Support\Collection;

/**
 * Find the saved filters that select a tag. A tag filter stores tag names,
 * so a rename or a delete of the tag changes the audience of these filters.
 * Only a newsletter that can still send counts (NewsletterStatus::unfinished()).
 * A sent or failed newsletter does not send again.
 */
class TagUsage
{
    /**
     * @return list<string>
     */
    public static function filtersOf(Tag $tag): array
    {
        $name = mb_strtolower($tag->name);
        $uses = fn (mixed $filters): bool => self::selects($filters, $name);

        $newsletters = Newsletter::query()
            ->where('client_id', $tag->client_id)
            ->whereIn('status', NewsletterStatus::unfinished())
            ->get(['id', 'title', 'filters'])
            ->filter(fn (Newsletter $newsletter): bool => $uses($newsletter->filters))
            ->map(fn (Newsletter $newsletter): string => "newsletter \"{$newsletter->title}\"");

        $sequences = Sequence::query()
            ->where('client_id', $tag->client_id)
            ->get(['id', 'name', 'filters']);

        $sequenceNames = $sequences
            ->filter(fn (Sequence $sequence): bool => $uses($sequence->filters))
            ->map(fn (Sequence $sequence): string => "sequence \"{$sequence->name}\"");

        $stepNames = SequenceStep::query()
            ->whereIn('sequence_id', $sequences->modelKeys())
            ->get(['id', 'sequence_id', 'filters'])
            ->filter(fn (SequenceStep $step): bool => $uses($step->filters))
            ->map(fn (SequenceStep $step): string => "a step of sequence \"{$sequences->find($step->sequence_id)?->name}\"");

        $segments = Segment::query()
            ->where('client_id', $tag->client_id)
            ->get(['id', 'name', 'filters'])
            ->filter(fn (Segment $segment): bool => $uses($segment->filters))
            ->map(fn (Segment $segment): string => "segment \"{$segment->name}\"");

        return (new Collection)
            ->merge($newsletters)
            ->merge($sequenceNames)
            ->merge($stepNames)
            ->merge($segments)
            ->unique()
            ->values()
            ->all();
    }

    private static function selects(mixed $filters, string $name): bool
    {
        if (! is_array($filters)) {
            return false;
        }

        foreach ($filters as $filter) {
            if (! is_array($filter) || ($filter['category'] ?? null) !== FilterCategory::Tags->value) {
                continue;
            }

            foreach ((array) ($filter['values'] ?? []) as $value) {
                if (is_string($value) && mb_strtolower($value) === $name) {
                    return true;
                }
            }
        }

        return false;
    }
}
