<?php

namespace App\Http\Resources;

use App\Models\Automation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Automation
 */
class AutomationResource extends JsonResource
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
            'type' => $this->type,
            'condition' => $this->condition,
            'actions' => $this->actions,
            'is_live' => $this->is_live,
            'created_at' => $this->created_at,
            'project_ids' => $this->whenLoaded('projects', fn () => $this->projects->pluck('id')->all()),
        ];
    }
}
