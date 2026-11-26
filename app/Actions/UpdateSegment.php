<?php

namespace App\Actions;

use App\Models\Segment;
use App\Models\User;
use App\Services\FilterPreparationService;
use App\Support\Filters\FilterSchema;

/**
 * @phpstan-import-type AudienceFilter from FilterSchema
 */
class UpdateSegment
{
    public function __construct(private readonly FilterPreparationService $filterPreparationService) {}

    /**
     * Change the given fields of a segment. A field that is not in the array
     * keeps its value. Newsletters and sequences that use the segment read
     * the new filters at once.
     *
     * @param  array{name?: string, description?: ?string, filters?: array<int, AudienceFilter>}  $changes
     */
    public function handle(User $user, Segment $segment, array $changes): Segment
    {
        $attributes = [];

        if (array_key_exists('name', $changes)) {
            $attributes['name'] = $changes['name'];
        }

        if (array_key_exists('description', $changes)) {
            $attributes['description'] = $changes['description'];
        }

        if (array_key_exists('filters', $changes)) {
            $attributes['filters'] = $this->filterPreparationService->prepareFilters($changes['filters'], $user);
        }

        if ($attributes !== []) {
            $segment->update($attributes);
        }

        return $segment;
    }
}
