<?php

namespace App\Mcp\Concerns;

use App\Models\Segment;
use App\Services\ContactFilterService;

trait FormatsSegment
{
    /**
     * The fields of a segment that the segment tools return. The contact
     * count includes unsubscribed contacts, the same as the dashboard.
     *
     * @return array{id: int, name: string, description: string|null, filters: array<int, mixed>, archived: bool, archived_at: string|null, contacts_count: int}
     */
    protected function segmentSummary(Segment $segment, ContactFilterService $contactFilterService): array
    {
        $filters = $segment->filters ?? [];

        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'description' => $segment->description,
            'filters' => $filters,
            'archived' => $segment->isArchived(),
            'archived_at' => $segment->archived_at?->toIso8601String(),
            'contacts_count' => $contactFilterService->segmentContactsQuery($segment->client_id, $filters)->count(),
        ];
    }
}
