<?php

namespace App\Http\Resources;

use App\Models\Sequence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sequence
 */
class SequenceSummaryResource extends JsonResource
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
            'steps_count' => (int) ($this->steps_count ?? 0),
            'contacts_count' => $contactsCount,
            'completed_count' => $completedCount,
            /** @var int */
            'active_count' => max($contactsCount - $completedCount, 0),
            /** @var list<int> */
            'project_ids' => $this->whenLoaded('projects', fn () => $this->projects->pluck('id')->all()),
        ];
    }
}
