<?php

namespace App\Actions;

use App\Models\Sequence;
use App\Models\User;
use App\Services\FilterPreparationService;
use App\Support\ContentVariables;
use App\Support\Filters\FilterSchema;

/**
 * @phpstan-import-type AudienceFilter from FilterSchema
 */
class UpdateSequence
{
    public function __construct(private readonly FilterPreparationService $filterPreparationService) {}

    /**
     * Change the given fields of a sequence. A field that is not in the
     * array keeps its value.
     *
     * The audience is a saved segment or inline filters, never both. A
     * segment clears the filters, and filters clear the segment.
     *
     * The content variables replace the saved defaults. Each key that the
     * caller sends is kept, also a key that no step uses yet, because a
     * caller can set the defaults before it adds the steps.
     *
     * @param  array{name?: string, filters?: array<int, AudienceFilter>, segment_id?: ?int, content_variables?: array<string, ?string>|null}  $changes
     */
    public function handle(User $user, Sequence $sequence, array $changes): Sequence
    {
        $attributes = [];

        if (array_key_exists('name', $changes)) {
            $attributes['name'] = $changes['name'];
        }

        if (($changes['segment_id'] ?? null) !== null) {
            $attributes['segment_id'] = $changes['segment_id'];
            $attributes['filters'] = [];
        } elseif (array_key_exists('filters', $changes)) {
            $attributes['segment_id'] = null;
            $attributes['filters'] = $this->filterPreparationService->prepareFilters($changes['filters'], $user);
        } elseif (array_key_exists('segment_id', $changes)) {
            $attributes['segment_id'] = null;
        }

        if (array_key_exists('content_variables', $changes)) {
            $attributes['content_variables'] = ContentVariables::normalize($changes['content_variables']);
        }

        if ($attributes !== []) {
            $sequence->forceFill($attributes)->save();
        }

        return $sequence;
    }
}
