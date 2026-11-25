<?php

namespace App\Http\Resources;

use App\Models\Sequence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sequence
 */
class SequenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contactsCount = (int) ($this->contacts_count ?? 0);
        $completedCount = (int) ($this->completed_count ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'contacts_count' => $contactsCount,
            'completed_count' => $completedCount,
            /** @var int */
            'active_count' => max($contactsCount - $completedCount, 0),
            'steps_count' => (int) ($this->steps_count ?? ($this->relationLoaded('steps') ? $this->steps->count() : 0)),
            /** @var list<SequenceStepResource> */
            'steps' => $this->relationLoaded('steps') ? SequenceStepResource::collection($this->steps)->resolve($request) : [],
            'segment_id' => $this->segment_id,
            'filters' => $this->effectiveFilters(),
            /** @var array<string, string> */
            'content_variables' => $this->content_variables ?? [],
            'created_at' => $this->created_at,
            /** @var list<int> */
            'project_ids' => $this->whenLoaded('projects', fn () => $this->projects->pluck('id')->all()),
        ];
    }
}
