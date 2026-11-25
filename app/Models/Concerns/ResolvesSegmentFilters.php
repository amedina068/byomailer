<?php

namespace App\Models\Concerns;

use App\Models\Segment;
use App\Support\Filters\FilterSchema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared audience-targeting logic for models that can either carry inline
 * filters or live-reference a saved {@see Segment}. Used by Newsletter and
 * Sequence.
 *
 * @property int|null $client_id
 * @property int|null $segment_id
 *
 * @phpstan-import-type AudienceFilter from FilterSchema
 *
 * @property array<int, AudienceFilter>|null $filters
 * @property-read Segment|null $segment
 */
trait ResolvesSegmentFilters
{
    /**
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * Resolve the filter set that defines this model's audience.
     *
     * When a saved segment is referenced its filters win (live reference);
     * otherwise the inline filters apply. A segment belonging to a different
     * client — only reachable in a no-auth background path where ClientScope
     * is a no-op — is rejected to `[]` as defence in depth.
     *
     * @return array<int, AudienceFilter>
     */
    public function effectiveFilters(): array
    {
        if ($this->segment_id === null) {
            return $this->filters ?? [];
        }

        $segment = $this->segment;

        if ($segment === null || $segment->client_id !== $this->client_id) {
            return [];
        }

        return $segment->filters ?? [];
    }
}
