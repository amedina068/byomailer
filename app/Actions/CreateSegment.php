<?php

namespace App\Actions;

use App\Models\Segment;
use App\Models\User;
use App\Services\FilterPreparationService;
use App\Support\Filters\FilterSchema;

/**
 * @phpstan-import-type AudienceFilter from FilterSchema
 */
class CreateSegment
{
    public function __construct(private readonly FilterPreparationService $filterPreparationService) {}

    /**
     * Create a segment in the client of the user. The filters get the same
     * preparation as the filters of a newsletter.
     *
     * @param  array{name: string, description?: ?string, filters: array<int, AudienceFilter>}  $data
     */
    public function handle(User $user, array $data): Segment
    {
        return Segment::create([
            'client_id' => $user->client_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'filters' => $this->filterPreparationService->prepareFilters($data['filters'], $user),
        ]);
    }
}
