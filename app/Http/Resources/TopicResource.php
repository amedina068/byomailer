<?php

namespace App\Http\Resources;

use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Topic
 */
class TopicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'list_id' => $this->list_id,
            'list_name' => $this->when(
                $this->relationLoaded('list'),
                fn () => $this->list?->name,
            ),
            'name' => $this->name,
            'description' => $this->description,
            'subscribed_count' => $this->when(
                isset($this->subscribed_count),
                fn () => $this->subscribed_count,
            ),
            'created_at' => $this->created_at,
        ];
    }
}
