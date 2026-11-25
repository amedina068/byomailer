<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'newsletters_count' => $this->whenCounted('newsletters'),
            'sequences_count' => $this->whenCounted('sequences'),
            'automations_count' => $this->whenCounted('automations'),
            'visual_automations_count' => $this->whenCounted('visualAutomations'),
            'created_at' => $this->created_at,
        ];
    }
}
