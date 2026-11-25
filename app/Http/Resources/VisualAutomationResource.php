<?php

namespace App\Http\Resources;

use App\Models\VisualAutomation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VisualAutomation
 */
class VisualAutomationResource extends JsonResource
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
            'name' => $this->name,
            'status' => $this->status,
            'workflow' => $this->workflow,
            'enrollments_count' => $this->when(isset($this->enrollments_count), fn () => $this->enrollments_count, 0),
            'created_at' => $this->created_at,
            'project_ids' => $this->whenLoaded('projects', fn () => $this->projects->pluck('id')->all()),
        ];
    }
}
