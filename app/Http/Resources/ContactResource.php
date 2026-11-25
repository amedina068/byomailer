<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tags = $this->relationLoaded('tags')
            ? $this->tags->map(fn ($tag) => TagResource::make($tag)->resolve($request))->all()
            : [];
        $lists = $this->relationLoaded('lists')
            ? $this->lists->map(fn ($list) => MailListResource::make($list)->resolve($request))->all()
            : [];
        $topics = $this->relationLoaded('topics')
            ? TopicResource::collection(
                $this->topics->filter(fn ($topic) => $topic->pivot->unsubscribed_at === null)->values()
            )->resolve($request)
            : [];
        $sequences = $this->relationLoaded('sequences')
            ? $this->sequences->map(fn ($sequence) => [
                'id' => $sequence->id,
                'name' => $sequence->name,
                'status' => $sequence->status->value,
            ])->all()
            : [];

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'extra_attributes' => $this->extra_attributes ?? [],
            'unsubscribed_at' => $this->unsubscribed_at,
            'unsubscribed_by_contact' => $this->unsubscribed_by_contact,
            'tags' => $tags,
            'lists' => $lists,
            'topics' => $topics,
            'sequences' => $sequences,
            'created_at' => $this->created_at,
        ];
    }
}
